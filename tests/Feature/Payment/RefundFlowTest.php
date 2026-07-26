<?php

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Event\CancelEvent;
use App\Actions\Payment\RefundBookingPayments;
use App\Actions\Payment\SettleBookingPayment;
use App\Actions\Payment\StartBookingPayment;
use App\Actions\Tenant\SetTenantFeature;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\CommissionItemState;
use App\Enums\EventStatus;
use App\Enums\Feature;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\BookingCommissionItem;
use App\Models\CommissionSetting;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/*
 * Refunds on a cancelled paid booking (docs/04 §5, SLO-131): the tenant's policy
 * decides the automatic amount, an admin can override it by hand, a cancelled
 * event refunds its registrants in full, and a reschedule refunds nothing. The
 * commission ledger is deliberately untouched by all of it (docs/10 §3).
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    config()->set('payments.sandbox.enabled', true);
    config()->set('payments.sandbox.secret', 'test-secret');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A tenant with online payment on and the given refund policy. */
function refundTenant(array $settings = []): Tenant
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'settings' => $settings,
    ]);
    app(SetTenantFeature::class)($tenant, Feature::OnlinePayment, true);
    app(TenantManager::class)->set($tenant);

    return $tenant;
}

/** A tenant user in the given role, with the team context set for role checks. */
function refundAdmin(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

/** A confirmed booking with one settled payment of `$paidMinor`. */
function paidBooking(Tenant $tenant, int $paidMinor = 100000, array $bookingOverrides = []): Booking
{
    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create(array_merge([
        'price_minor' => $paidMinor,
        'currency' => 'HUF',
        'starts_at' => Carbon::now()->addWeek(),
        'ends_at' => Carbon::now()->addWeek()->addHour(),
    ], $bookingOverrides));

    Payment::factory()->forBooking($booking)->paid()->create(['amount_minor' => $paidMinor]);

    return $booking;
}

it('refunds nothing by default', function () {
    $tenant = refundTenant();
    $booking = paidBooking($tenant);

    app(CancelBooking::class)($booking);

    expect(Refund::withoutGlobalScopes()->count())->toBe(0)
        ->and(Payment::withoutGlobalScopes()->sole()->status)->toBe(PaymentStatus::Paid);
});

it('refunds the whole payment under a full policy and marks the payment refunded', function () {
    $tenant = refundTenant(['refund_policy' => 'full']);
    $booking = paidBooking($tenant, 250000);

    app(CancelBooking::class)($booking);

    $refund = Refund::withoutGlobalScopes()->sole();
    expect($refund->amount_minor)->toBe(250000)
        ->and($refund->tenant_id)->toBe($tenant->id)
        // The sync queue runs ProcessRefund inline, so it is already settled.
        ->and($refund->status)->toBe(RefundStatus::Completed)
        ->and($refund->provider_ref)->not->toBeNull()
        ->and(Payment::withoutGlobalScopes()->sole()->status)->toBe(PaymentStatus::Refunded);
});

it('refunds the policy share under a partial policy, floored', function () {
    // 50% of 12 345 minor = 6 172,5 → floor 6 172. Money never rounds up.
    $tenant = refundTenant(['refund_policy' => 'partial', 'refund_percent_bps' => 5000]);
    $booking = paidBooking($tenant, 12345);

    app(CancelBooking::class)($booking);

    $refund = Refund::withoutGlobalScopes()->sole();
    expect($refund->amount_minor)->toBe(6172)
        // A partial refund leaves the payment `paid` — only a full one flips it.
        ->and(Payment::withoutGlobalScopes()->sole()->status)->toBe(PaymentStatus::Paid);
});

it('never refunds more than is left on the payment', function () {
    $tenant = refundTenant(['refund_policy' => 'full']);
    $booking = paidBooking($tenant, 100000);
    $payment = Payment::withoutGlobalScopes()->sole();

    // 60% already went back by hand before the cancellation.
    app(RefundBookingPayments::class)($booking, 60000);
    app(CancelBooking::class)($booking);

    expect((int) Refund::withoutGlobalScopes()->sum('amount_minor'))->toBe(100000)
        ->and(Refund::withoutGlobalScopes()->count())->toBe(2)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded);

    // And nothing is refundable any more.
    expect(app(RefundBookingPayments::class)->refundableMinor($booking))->toBe(0);
    expect(app(RefundBookingPayments::class)($booking, 1))->toBe([]);
});

it('refunds nothing for the cancellation half of a reschedule', function () {
    $tenant = refundTenant(['refund_policy' => 'full']);

    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
        'price_minor' => 100000,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    $booking = paidBooking($tenant, 100000, [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'booking_mode' => BookingMode::DurationBased,
    ]);

    app(RescheduleBooking::class)($booking, $service, [
        'service_id' => $service->id,
        'customer_id' => $booking->customer_id,
        'staff_id' => $staff->id,
        'starts_at' => Carbon::now()->addWeeks(2)->toDateTimeString(),
        'ends_at' => Carbon::now()->addWeeks(2)->addHour()->toDateTimeString(),
        'party_size' => 1,
        'source' => 'admin',
    ]);

    // The customer keeps their appointment, so the money stays put (docs/04 §2).
    expect(Refund::withoutGlobalScopes()->count())->toBe(0);
});

it('refunds every registrant in full when the tenant cancels an event', function () {
    // The tenant called it off, so the cancellation share does not apply.
    $tenant = refundTenant(['refund_policy' => 'none']);

    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::EventBased,
        'capacity' => 10,
        'active' => true,
    ]);
    $event = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'status' => EventStatus::Scheduled,
        'capacity' => 10,
        'booked_count' => 2,
    ]);

    $first = paidBooking($tenant, 50000, [
        'service_id' => $service->id,
        'booking_mode' => BookingMode::EventBased,
        'event_id' => $event->id,
    ]);
    $second = paidBooking($tenant, 30000, [
        'service_id' => $service->id,
        'booking_mode' => BookingMode::EventBased,
        'event_id' => $event->id,
    ]);

    app(CancelEvent::class)($event, false);

    expect($first->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($second->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and(Refund::withoutGlobalScopes()->count())->toBe(2)
        ->and((int) Refund::withoutGlobalScopes()->sum('amount_minor'))->toBe(80000);
});

it('leaves the commission ledger untouched by a refund', function () {
    CommissionSetting::factory()->create([
        'free_threshold_minor' => 0,
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'monthly_cap_minor' => null,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
    $tenant = refundTenant(['refund_policy' => 'full']);

    // A late cancellation stays commission-bearing (docs/10 §3.1): slot4u bills on
    // the list price even though the tenant handed the money back.
    $booking = paidBooking($tenant, 200000, ['starts_at' => Carbon::now()->addHours(2), 'ends_at' => Carbon::now()->addHours(3)]);
    $item = BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole();

    app(CancelBooking::class)($booking);

    expect(Refund::withoutGlobalScopes()->sole()->amount_minor)->toBe(200000)
        ->and($item->fresh()->state)->toBe(CommissionItemState::Billable)
        ->and($item->fresh()->amount_minor)->toBe(200000);
});

it('refunds a booking whose payment landed after its hold expired', function () {
    $tenant = refundTenant();
    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::PendingPayment)->create([
        'price_minor' => 90000,
        'starts_at' => Carbon::now()->addWeek(),
        'ends_at' => Carbon::now()->addWeek()->addHour(),
    ]);
    $payment = Payment::factory()->forBooking($booking)->create(['amount_minor' => 90000]);

    // The sweep released the slot first; the gateway callback lands afterwards.
    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);
    app(SettleBookingPayment::class)($payment);

    // The customer has nothing to show for the money, so it all goes back —
    // regardless of the tenant's cancellation refund policy (`none` here).
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and(Refund::withoutGlobalScopes()->sole()->amount_minor)->toBe(90000)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Canceled);
});

it('charges only the deposit for a rental that asks for one', function () {
    $tenant = refundTenant();
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental,
        'duration_minutes' => 60,
        'active' => true,
        'price_minor' => 500000,
        'settings' => ['deposit_minor' => 100000],
    ]);

    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::PendingPayment)->create([
        'service_id' => $service->id,
        'booking_mode' => BookingMode::ResourceRental,
        'price_minor' => 500000,
    ]);

    app(StartBookingPayment::class)($booking, 'http://acme.slot4u.test/booked/x');

    // Only the deposit is taken online; the rest is settled on site (docs/04 §4).
    expect(Payment::withoutGlobalScopes()->sole()->amount_minor)->toBe(100000)
        // The booking (and with it the commission base) still carries the full price.
        ->and($booking->fresh()->price_minor)->toBe(500000);
});

it('ignores a deposit that is not smaller than the price, or on another mode', function () {
    $tenant = refundTenant();

    $rental = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental,
        'price_minor' => 100000,
        'settings' => ['deposit_minor' => 100000],
    ]);
    $timed = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'price_minor' => 100000,
        'settings' => ['deposit_minor' => 20000],
    ]);

    expect($rental->depositMinor())->toBeNull()
        ->and($timed->depositMinor())->toBeNull();
});

it('lets an admin refund by hand from the booking page', function () {
    $tenant = refundTenant();
    $booking = paidBooking($tenant, 100000);

    $admin = refundAdmin($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/refund"), [
            'amount_minor' => 40000,
            'reason' => 'Jóindulatú visszatérítés',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $refund = Refund::withoutGlobalScopes()->sole();
    expect($refund->amount_minor)->toBe(40000)
        ->and($refund->reason)->toBe('Jóindulatú visszatérítés')
        ->and($refund->status)->toBe(RefundStatus::Completed);

    // The booking page shows what is left.
    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings/{$booking->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('refundable_minor', 60000)
            ->where('payments.0.refunds.0.amount_minor', 40000)
        );
});

it('refuses a manual refund when nothing is refundable', function () {
    $tenant = refundTenant();
    // Never paid → nothing to give back.
    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create(['price_minor' => 100000]);

    $admin = refundAdmin($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/refund"), ['amount_minor' => 1000])
        ->assertSessionHasErrors('amount_minor');

    expect(Refund::withoutGlobalScopes()->count())->toBe(0);
});

it('hides another staff member\'s booking from an employee refunding', function () {
    $tenant = refundTenant();
    // Not the employee's own booking: an employee only sees their own (docs/03),
    // so it 404s like a cross-tenant id — the same "hidden existence" rule the
    // cancel action follows, applied to the money side of it.
    $booking = paidBooking($tenant, 100000, ['staff_id' => Staff::factory()->forTenant($tenant)->create()->id]);

    $employee = refundAdmin($tenant, Role::Employee);

    $this->actingAs($employee)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/refund"), ['amount_minor' => 1000])
        ->assertNotFound();

    expect(Refund::withoutGlobalScopes()->count())->toBe(0);
});

it('404s a refund on another tenant\'s booking', function () {
    $tenant = refundTenant();
    $admin = refundAdmin($tenant, Role::TenantAdmin);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    // Bind the other tenant while creating its booking: with acme bound, the
    // BelongsToTenant creating hook would stamp acme on it (and it would not be
    // foreign at all).
    app(TenantManager::class)->set($other);
    $foreign = paidBooking($other, 100000);
    app(TenantManager::class)->set($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$foreign->id}/refund"), ['amount_minor' => 1000])
        ->assertNotFound();

    expect(Refund::withoutGlobalScopes()->count())->toBe(0);
});

it('isolates refunds between tenants', function () {
    $tenant = refundTenant();
    $booking = paidBooking($tenant);
    app(RefundBookingPayments::class)($booking, 5000);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);

    expect(Refund::query()->count())->toBe(0)
        ->and(Refund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});
