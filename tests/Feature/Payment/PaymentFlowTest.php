<?php

use App\Actions\Booking\ApproveBooking;
use App\Actions\Tenant\SetTenantFeature;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Services\Payment\Gateways\SandboxGateway;
use App\Tenancy\TenantManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature as Pennant;
use Spatie\Permission\PermissionRegistrar;

/*
 * Customer online payment (SLO-40 / SLO-130): the pending_payment → confirmed flow
 * end to end on the sandbox gateway, the gateway callback (signature, idempotency,
 * tenant isolation), and the expiry sweep that hands an unpaid slot back.
 */

beforeEach(function () {
    // Customer creation assigns the `customer` role (FindOrCreateCustomer).
    $this->seed(PermissionSeeder::class);
    config()->set('payments.sandbox.enabled', true);
    config()->set('payments.sandbox.secret', 'test-secret');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * A tenant with the online-payment integration on and a pay-first duration_based
 * service, bookable on 2026-09-07 09:00 local (= 07:00Z).
 *
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function payService(array $serviceOverrides = []): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(SetTenantFeature::class)($tenant, Feature::OnlinePayment, true);

    $service = Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
        'online_payment_required' => true,
        'price_minor' => 1250000,
        'currency' => 'HUF',
    ], $serviceOverrides));

    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(Carbon::parse('2026-09-07')->isoWeekday(), '09:00', '17:00')
        ->create();

    return [$tenant, $service, $staff];
}

/**
 * @return array<string, mixed>
 */
function payPayload(Service $service, Staff $staff, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07T07:00:00Z',
        'ends_at' => '2026-09-07T08:00:00Z',
        'name' => 'Teszt Vendég',
        'email' => 'guest@example.test',
        'phone' => '+3611234567',
    ], $overrides);
}

/** Book the slot publicly and walk to the sandbox checkout, returning [booking, payment]. */
function bookAndCheckout(Service $service, Staff $staff, array $overrides = []): array
{
    test()->post(tenantHost('acme', '/book'), payPayload($service, $staff, $overrides))
        ->assertSessionHasNoErrors();

    $booking = Booking::withoutGlobalScopes()->where('service_id', $service->id)->latest('id')->firstOrFail();

    test()->get(tenantHost('acme', '/pay/'.$booking->code))
        ->assertRedirect();

    $payment = Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->latest('id')->firstOrFail();

    return [$booking, $payment];
}

it('sends a pay-first booking to checkout and confirms it once paid', function () {
    [$tenant, $service, $staff] = payService();

    $this->post(tenantHost('acme', '/book'), payPayload($service, $staff))
        ->assertSessionHasNoErrors();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($booking->status)->toBe(BookingStatus::PendingPayment)
        // The slot is held only until the payment window elapses (SLO-130).
        ->and($booking->hold_expires_at)->not->toBeNull();

    // The public flow lands the customer on the checkout, not the confirmation.
    $this->post(tenantHost('acme', '/book'), payPayload($service, $staff, [
        'starts_at' => '2026-09-07T08:00:00Z',
        'ends_at' => '2026-09-07T09:00:00Z',
    ]))->assertRedirect('/pay/'.Booking::withoutGlobalScopes()->latest('id')->first()->code);

    // Opening the checkout records a pending attempt and redirects to the gateway.
    $this->get(tenantHost('acme', '/pay/'.$booking->code))
        ->assertRedirect();

    $payment = Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->sole();
    expect($payment->tenant_id)->toBe($tenant->id)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider)->toBe(PaymentProvider::Sandbox)
        ->and($payment->amount_minor)->toBe(1250000)
        ->and($payment->currency)->toBe('HUF')
        ->and($payment->provider_ref)->not->toBeNull();

    // Paying on the sandbox checkout confirms the booking through the state machine.
    $this->post(tenantHost('acme', '/payments/sandbox/'.$payment->provider_ref), ['outcome' => 'paid'])
        ->assertRedirect('/booked/'.$booking->code);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->paid_at)->not->toBeNull()
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        // Leaving the payment window drops the hold, so no sweep can touch it.
        ->and($booking->fresh()->hold_expires_at)->toBeNull();
});

it('leaves the booking payable when the payment is declined', function () {
    [, $service, $staff] = payService();
    [$booking, $payment] = bookAndCheckout($service, $staff);

    $this->post(tenantHost('acme', '/payments/sandbox/'.$payment->provider_ref), ['outcome' => 'failed'])
        ->assertRedirect('/booked/'.$booking->code);

    // A refused card is not a cancelled booking — the customer may retry.
    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);

    $this->get(tenantHost('acme', '/pay/'.$booking->code))->assertRedirect();

    expect(Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->count())->toBe(2);
});

it('offers the payment link on the confirmation page while the booking is unpaid', function () {
    [, $service, $staff] = payService();
    [$booking] = bookAndCheckout($service, $staff);

    $this->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Booked')
            ->where('booking.payable', true)
            ->where('booking.price_minor', 1250000)
            ->where('booking.payment_deadline_local', $booking->hold_expires_at->copy()->timezone($booking->tenant->timezone)->format('Y-m-d H:i'))
        );
});

it('closes the other open attempt when one of them is paid', function () {
    [, $service, $staff] = payService();
    [$booking, $first] = bookAndCheckout($service, $staff);

    // The customer reopens the checkout in a second tab, then pays in the first.
    $this->get(tenantHost('acme', '/pay/'.$booking->code))->assertRedirect();
    $second = Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->latest('id')->firstOrFail();

    $this->post(tenantHost('acme', '/payments/sandbox/'.$first->provider_ref), ['outcome' => 'paid']);

    expect($first->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($second->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('bounces a checkout for a booking that is no longer awaiting payment', function () {
    [, $service, $staff] = payService();
    [$booking, $payment] = bookAndCheckout($service, $staff);

    $this->post(tenantHost('acme', '/payments/sandbox/'.$payment->provider_ref), ['outcome' => 'paid']);

    $this->get(tenantHost('acme', '/pay/'.$booking->code))
        ->assertRedirect('/booked/'.$booking->code);

    // No second charge was opened for an already confirmed booking.
    expect(Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->count())->toBe(1);
});

it('settles a payment from a signed gateway callback, idempotently', function () {
    [, $service, $staff] = payService();
    [$booking, $payment] = bookAndCheckout($service, $staff);

    $body = ['reference' => $payment->provider_ref, 'status' => 'paid'];
    $signature = SandboxGateway::sign((string) $payment->provider_ref, 'paid');

    $this->postJson(tenantHost('acme', '/payments/webhook/sandbox'), $body, [
        SandboxGateway::SIGNATURE_HEADER => $signature,
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $paidAt = $payment->fresh()->paid_at;

    // Gateways retry: the replay must change nothing (one confirmation, one ledger
    // entry, one status history row for the transition).
    $this->postJson(tenantHost('acme', '/payments/webhook/sandbox'), $body, [
        SandboxGateway::SIGNATURE_HEADER => $signature,
    ])->assertOk();

    expect($payment->fresh()->paid_at->eq($paidAt))->toBeTrue()
        ->and($booking->statusHistory()->where('to_status', BookingStatus::Confirmed->value)->count())->toBe(1);
});

it('refuses an unsigned or forged gateway callback', function () {
    [, $service, $staff] = payService();
    [$booking, $payment] = bookAndCheckout($service, $staff);

    $this->postJson(tenantHost('acme', '/payments/webhook/sandbox'), [
        'reference' => $payment->provider_ref,
        'status' => 'paid',
    ], [SandboxGateway::SIGNATURE_HEADER => 'forged'])->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
});

it('404s a callback whose reference belongs to another tenant', function () {
    [, $service, $staff] = payService();
    [, $payment] = bookAndCheckout($service, $staff);

    Tenant::factory()->active()->create(['slug' => 'other']);

    $this->postJson(tenantHost('other', '/payments/webhook/sandbox'), [
        'reference' => $payment->provider_ref,
        'status' => 'paid',
    ], [
        SandboxGateway::SIGNATURE_HEADER => SandboxGateway::sign((string) $payment->provider_ref, 'paid'),
    ])->assertNotFound();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('records a callback for a suspended tenant', function () {
    [$tenant, $service, $staff] = payService();
    [$booking, $payment] = bookAndCheckout($service, $staff);

    // Money that arrived is a fact, not a permission (the SLO-120 pattern).
    $tenant->update(['status' => TenantStatus::Suspended]);

    $this->postJson(tenantHost('acme', '/payments/webhook/sandbox'), [
        'reference' => $payment->provider_ref,
        'status' => 'paid',
    ], [
        SandboxGateway::SIGNATURE_HEADER => SandboxGateway::sign((string) $payment->provider_ref, 'paid'),
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('hides the sandbox checkout when the gateway is disabled', function () {
    [, $service, $staff] = payService();
    [, $payment] = bookAndCheckout($service, $staff);

    config()->set('payments.sandbox.enabled', false);

    $this->get(tenantHost('acme', '/payments/sandbox/'.$payment->provider_ref))->assertNotFound();
    $this->post(tenantHost('acme', '/payments/sandbox/'.$payment->provider_ref), ['outcome' => 'paid'])
        ->assertNotFound();
});

it('closes the payment routes when the tenant has no online-payment integration', function () {
    [$tenant, $service, $staff] = payService();
    [$booking, $payment] = bookAndCheckout($service, $staff);

    app(SetTenantFeature::class)($tenant, Feature::OnlinePayment, false);
    // Pennant memoises resolved flags for the lifetime of the process; a test that
    // toggles one mid-run has to drop that cache to see the new value.
    Pennant::flushCache();

    // 403, not 404: the capability is off for this tenant, not hidden (docs/03).
    $this->get(tenantHost('acme', '/pay/'.$booking->code))->assertForbidden();
    $this->get(tenantHost('acme', '/payments/sandbox/'.$payment->provider_ref))->assertForbidden();

    // The confirmation page then offers no payment link at all.
    $this->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('booking.payable', false));
});

it('renders the sandbox checkout with the amount to pay', function () {
    [, $service, $staff] = payService();
    [$booking, $payment] = bookAndCheckout($service, $staff);

    $this->get(tenantHost('acme', '/payments/sandbox/'.$payment->provider_ref))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/SandboxCheckout')
            ->where('payment.amount_minor', 1250000)
            ->where('booking.code', $booking->code)
        );
});

it('releases the slot when the payment window expires', function () {
    [$tenant, $service, $staff] = payService();
    [$booking, $payment] = bookAndCheckout($service, $staff);

    // Not yet due: the sweep leaves it alone.
    $this->artisan('bookings:expire-pending-payments')->assertSuccessful();
    expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);

    Carbon::setTestNow(Carbon::now()->addMinutes(31));

    $this->artisan('bookings:expire-pending-payments')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($booking->fresh()->cancel_reason)->toBe(__('app.booking.reason.payment_expired'))
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Failed);

    // And the slot is genuinely free again: the same time books.
    $this->post(tenantHost('acme', '/book'), payPayload($service, $staff, [
        'email' => 'second@example.test',
    ]))->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)
        ->where('status', BookingStatus::PendingPayment->value)->count())->toBe(1);
});

it('honours the tenant payment hold window', function () {
    [$tenant, $service, $staff] = payService();
    $tenant->update(['settings' => ['payment_hold_minutes' => 5]]);

    $this->post(tenantHost('acme', '/book'), payPayload($service, $staff))->assertSessionHasNoErrors();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($booking->hold_expires_at->diffInMinutes(Carbon::now(), true))->toEqualWithDelta(5, 1);
});

it('starts the payment window when an approved booking becomes payable', function () {
    // Approval + payment: the booking only reaches pending_payment on the approve
    // transition, so the deadline cannot have been stamped at creation (SLO-130).
    [$tenant, $service, $staff] = payService(['requires_approval' => true]);

    $this->post(tenantHost('acme', '/book'), payPayload($service, $staff))->assertSessionHasNoErrors();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($booking->status)->toBe(BookingStatus::Requested);

    app(ApproveBooking::class)($booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment)
        ->and($booking->fresh()->hold_expires_at)->not->toBeNull()
        ->and($booking->fresh()->hold_expires_at->diffInMinutes(Carbon::now(), true))
        ->toEqualWithDelta(30, 1);
});

it('isolates payments between tenants', function () {
    [$tenant, $service, $staff] = payService();
    [, $payment] = bookAndCheckout($service, $staff);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);

    // The global scope hides the acme payment from the other tenant entirely.
    expect(Payment::query()->count())->toBe(0)
        ->and(Payment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and($payment->tenant_id)->toBe($tenant->id);
});
