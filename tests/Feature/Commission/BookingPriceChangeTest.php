<?php

use App\Actions\Booking\UpdateBookingPrice;
use App\Actions\Commission\RecomputeTenantPeriod;
use App\Enums\AuditAction;
use App\Enums\BillingPeriodStatus;
use App\Enums\BookingStatus;
use App\Enums\CommissionCorrectionType;
use App\Enums\InvoiceProvider;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingCommissionItem;
use App\Models\CommissionCorrection;
use App\Models\CommissionSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * Admin price edit → commission ledger (SLO-126, docs/10 §3.3).
 *
 * The list price IS the commission base, so editing it has to reach the ledger
 * immediately. Before this the ledger only ever synced on a status change, so a
 * corrected price sat in the booking while the tenant was billed on the old one
 * until — if ever — the booking changed status again.
 *
 * Also covers the two edits that are refused: the price must not diverge from a
 * checkout the customer is looking at, or from an invoice already in their hands.
 */

const PRICE_NOW = '2026-07-16 09:00:00';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow(PRICE_NOW);

    CommissionSetting::factory()->create([
        'free_threshold_minor' => 0,
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'monthly_cap_minor' => null,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A confirmed booking that became billable in the given period. */
function pricedBooking(Tenant $tenant, int $amount, string $period = '2026-07'): Booking
{
    Carbon::setTestNow(Carbon::parse("{$period}-10 12:00:00"));

    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'price_minor' => $amount,
        'starts_at' => '2026-09-01 10:00:00',
        'ends_at' => '2026-09-01 11:00:00',
    ]);

    Carbon::setTestNow(PRICE_NOW);

    return $booking;
}

function priceLedger(Booking $booking): BookingCommissionItem
{
    return BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->getKey())->sole();
}

function pricePeriod(Tenant $tenant, string $period = '2026-07'): TenantBillingPeriod
{
    return TenantBillingPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('period', $period)
        ->sole();
}

function priceAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

// ------------------------------------------------------------- open period

it('follows a price increase into the ledger and the period', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 100_000);

    expect(priceLedger($booking)->amount_minor)->toBe(100_000)
        ->and(pricePeriod($tenant)->commission_minor)->toBe(1_000);

    app(UpdateBookingPrice::class)($booking, 250_000);

    expect(priceLedger($booking)->amount_minor)->toBe(250_000)
        ->and(pricePeriod($tenant)->turnover_minor)->toBe(250_000)
        // 1% of 250 000 — the whole point: without the event this stayed 1 000.
        ->and(pricePeriod($tenant)->commission_minor)->toBe(2_500);
});

it('follows a price reduction into the ledger and the period', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 400_000);

    app(UpdateBookingPrice::class)($booking, 100_000);

    expect(priceLedger($booking)->amount_minor)->toBe(100_000)
        ->and(pricePeriod($tenant)->commission_minor)->toBe(1_000);
});

it('leaves the frozen rate snapshot alone when the price changes', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 100_000);

    $before = priceLedger($booking);
    $rate = $before->rate_bps;
    $realizedAt = $before->realized_at;

    app(UpdateBookingPrice::class)($booking, 300_000);

    $after = priceLedger($booking);

    // Only the amount moves — the rate/period/realized_at snapshot is what makes
    // a mid-month integration toggle non-retroactive (docs/10 §2.4).
    expect($after->rate_bps)->toBe($rate)
        ->and($after->realized_at->timestamp)->toBe($realizedAt->timestamp)
        ->and($after->period)->toBe($before->period);
});

it('does nothing at all when the price is unchanged', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 100_000);

    app(UpdateBookingPrice::class)($booking, 100_000);

    expect(AuditLog::query()->where('action', AuditAction::BookingPriceChanged->value)->count())->toBe(0);
});

it('records the old and new price in the audit trail', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 100_000);

    app(UpdateBookingPrice::class)($booking, 120_000);

    $log = AuditLog::query()->where('action', AuditAction::BookingPriceChanged->value)->sole();

    expect($log->old_values)->toBe(['price_minor' => 100_000])
        ->and($log->new_values)->toBe(['price_minor' => 120_000])
        ->and($log->tenant_id)->toBe($tenant->id);
});

it('leaves a booking that never became billable out of the ledger', function () {
    $tenant = Tenant::factory()->active()->create();

    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Requested)->create([
        'price_minor' => 100_000,
    ]);

    app(UpdateBookingPrice::class)($booking, 200_000);

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->getKey())->exists())
        ->toBeFalse();
});

// ----------------------------------------------------------- closed period

it('credits the open period when the price of an invoiced booking drops', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 400_000, '2026-06');

    $closed = app(RecomputeTenantPeriod::class)($tenant->getKey(), '2026-06');
    $closed->status = BillingPeriodStatus::Invoiced;
    $closed->save();

    expect($closed->commission_minor)->toBe(4_000);

    // Halving the price of a June booking in July: June is accounting-stable, so
    // the difference lands as a credit on July (docs/10 §8.2). This is the path
    // SLO-119 built and could only test through the action directly — a price
    // edit is what drives it in the product.
    app(UpdateBookingPrice::class)($booking, 200_000);

    $correction = CommissionCorrection::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->sole();

    expect($correction->type)->toBe(CommissionCorrectionType::BookingAdjustment)
        ->and($correction->source_period)->toBe('2026-06')
        ->and($correction->period)->toBe('2026-07')
        ->and($correction->commission_delta_minor)->toBe(-2_000);

    // The invoiced month itself is untouched.
    expect(pricePeriod($tenant, '2026-06')->commission_minor)->toBe(4_000)
        ->and(priceLedger($booking)->amount_minor)->toBe(400_000);
});

it('does not charge retroactively when the price of an invoiced booking rises', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 200_000, '2026-06');

    $closed = app(RecomputeTenantPeriod::class)($tenant->getKey(), '2026-06');
    $closed->status = BillingPeriodStatus::Invoiced;
    $closed->save();

    app(UpdateBookingPrice::class)($booking, 400_000);

    // Only credits are issued for a closed period (docs/10 §8.2) — an increase
    // never becomes a retroactive charge.
    expect(CommissionCorrection::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count())->toBe(0);
});

// ------------------------------------------------------------------ guards

it('refuses to change the price while a payment is in flight', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 100_000);

    Payment::factory()->forBooking($booking)->create(['status' => PaymentStatus::Pending]);

    expect(fn () => app(UpdateBookingPrice::class)($booking, 200_000))
        ->toThrow(ValidationException::class);

    expect($booking->fresh()->price_minor)->toBe(100_000);
});

it('refuses to change the price after an invoice was issued', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 100_000);

    $payment = Payment::factory()->forBooking($booking)->create(['status' => PaymentStatus::Paid]);
    Invoice::factory()->forPayment($payment)->issued()->create(['provider' => InvoiceProvider::Sandbox]);

    expect(fn () => app(UpdateBookingPrice::class)($booking, 200_000))
        ->toThrow(ValidationException::class);

    expect($booking->fresh()->price_minor)->toBe(100_000);
});

it('allows the edit once a settled payment carries no issued invoice', function () {
    $tenant = Tenant::factory()->active()->create();
    $booking = pricedBooking($tenant, 100_000);

    $payment = Payment::factory()->forBooking($booking)->create(['status' => PaymentStatus::Paid]);
    Invoice::factory()->forPayment($payment)->create(['status' => InvoiceStatus::Failed]);

    app(UpdateBookingPrice::class)($booking, 150_000);

    expect($booking->fresh()->price_minor)->toBe(150_000);
});

// ---------------------------------------------------------------- endpoint

it('lets an admin edit the price from the booking page', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $booking = pricedBooking($tenant, 100_000);

    $this->actingAs(priceAdmin($tenant))
        ->post(tenantHost('acme', "/bookings/{$booking->id}/price"), ['price_minor' => 175_000])
        ->assertRedirect();

    expect($booking->fresh()->price_minor)->toBe(175_000)
        ->and(priceLedger($booking)->amount_minor)->toBe(175_000);
});

it('rejects a negative price', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $booking = pricedBooking($tenant, 100_000);

    $this->actingAs(priceAdmin($tenant))
        ->post(tenantHost('acme', "/bookings/{$booking->id}/price"), ['price_minor' => -1])
        ->assertSessionHasErrors('price_minor');

    expect($booking->fresh()->price_minor)->toBe(100_000);
});

it('accepts a comped booking at zero', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $booking = pricedBooking($tenant, 100_000);

    $this->actingAs(priceAdmin($tenant))
        ->post(tenantHost('acme', "/bookings/{$booking->id}/price"), ['price_minor' => 0])
        ->assertRedirect();

    expect($booking->fresh()->price_minor)->toBe(0)
        ->and(pricePeriod($tenant)->commission_minor)->toBe(0);
});

it('404s a price edit aimed at another tenant booking', function () {
    $acme = Tenant::factory()->active()->create(['slug' => 'acme']);
    $bolt = Tenant::factory()->active()->create(['slug' => 'bolt']);
    $foreign = pricedBooking($bolt, 100_000);

    $this->actingAs(priceAdmin($acme))
        ->post(tenantHost('acme', "/bookings/{$foreign->id}/price"), ['price_minor' => 1])
        ->assertNotFound();

    expect($foreign->fresh()->price_minor)->toBe(100_000);
});

it('404s an employee editing a booking that is not theirs', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $booking = pricedBooking($tenant, 100_000);

    // Every staff role holds booking.edit (docs/03), so the meaningful
    // restriction is the employee ownership scope, not the permission — and an
    // unowned booking 404s rather than 403s, like a cross-tenant id.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee = User::factory()->create(['tenant_id' => $tenant->id]);
    $employee->assignRole(Role::Employee->value);

    $this->actingAs($employee)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/price"), ['price_minor' => 1])
        ->assertNotFound();

    expect($booking->fresh()->price_minor)->toBe(100_000);
});
