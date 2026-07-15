<?php

use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Commission\UpsertBookingCommissionItem;
use App\Actions\Tenant\SetTenantFeature;
use App\Enums\BillingPeriodStatus;
use App\Enums\BookingStatus;
use App\Enums\CommissionItemState;
use App\Enums\Feature;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\BookingCommissionItem;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
 * The commission ledger (docs/10 §6.3) driven by the booking lifecycle: a booking
 * becoming billable writes a frozen ledger entry and recomputes the tenant's
 * monthly aggregate; leaving the billable set removes it. Covers docs/10 §12/10-19
 * (billable rules, rate snapshot, period keying, idempotency, determinism) and the
 * §12/21 tenant-isolation contract for booking_commission_items.
 */

beforeEach(function () {
    // Freeze the clock so realized_at / period keys are deterministic.
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(TenantManager::class)->forget();
});

/** Seed the platform commission settings the ledger resolves against. */
function commissionSetting(int $threshold = 0, ?int $cap = null): CommissionSetting
{
    return CommissionSetting::factory()->create([
        'free_threshold_minor' => $threshold,
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'monthly_cap_minor' => $cap,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
}

/** A booking in the given tenant + status, created through the model (fires BookingCreated). */
function ledgerBooking(Tenant $tenant, BookingStatus $status, int $priceMinor = 500000, ?string $startsAt = '2026-09-01 10:00:00'): Booking
{
    return Booking::factory()->forTenant($tenant)->status($status)->create([
        'price_minor' => $priceMinor,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt === null ? null : '2026-09-01 11:00:00',
    ]);
}

it('records a billable ledger entry and aggregate when a booking is created confirmed', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();

    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 300000);

    $item = BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole();
    expect($item->tenant_id)->toBe($tenant->id)
        ->and($item->state)->toBe(CommissionItemState::Billable)
        ->and($item->amount_minor)->toBe(300000)
        ->and($item->rate_bps)->toBe(100)
        ->and($item->period)->toBe('2026-06')
        ->and($item->currency)->toBe('HUF');

    $period = TenantBillingPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)->where('period', '2026-06')->sole();
    // threshold 0, 1% of 300000 = 3000.
    expect($period->turnover_minor)->toBe(300000)
        ->and($period->commission_minor)->toBe(3000)
        ->and($period->cap_reached)->toBeFalse();
});

it('promotes a booking to billable when it transitions into confirmed', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = ledgerBooking($tenant, BookingStatus::Approved);

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->exists())->toBeFalse();

    app(ChangeBookingStatus::class)($booking, BookingStatus::Confirmed);

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->where('state', 'billable')->exists())->toBeTrue();
});

it('never records a ledger entry for requested, approved or rejected bookings', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = ledgerBooking($tenant, BookingStatus::Requested);

    app(ChangeBookingStatus::class)($booking, BookingStatus::Rejected);

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->exists())->toBeFalse()
        ->and(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

it('never bills a requested booking cancelled within 24h of its start without ever being confirmed', function () {
    // §3.1: a requested hold that expires and auto-cancels close to its start must
    // NOT accrue commission — it was never confirmed. Guards against the "late
    // cancellation" rule mistaking a never-billable booking for a billable one.
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    // starts_at one hour from now → cancelling now is "within 24h".
    $booking = ledgerBooking($tenant, BookingStatus::Requested, 400000, '2026-06-15 13:00:00');

    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled, null, 'hold expired');

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->exists())->toBeFalse()
        ->and(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

it('keeps no_show and completed bookings billable', function (BookingStatus $to) {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 200000);

    app(ChangeBookingStatus::class)($booking, $to);

    $item = BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole();
    expect($item->state)->toBe(CommissionItemState::Billable);

    $period = TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($period->turnover_minor)->toBe(200000);
})->with([
    'no_show' => [BookingStatus::NoShow],
    'completed' => [BookingStatus::Completed],
]);

it('removes the entry and drops turnover on a 24h+ early cancellation', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    // starts_at is far in the future (2026-09-01), now is 2026-06-15 → >24h before start.
    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 400000);

    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled, null, 'changed plans');

    $item = BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole();
    expect($item->state)->toBe(CommissionItemState::Removed);

    $period = TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($period->turnover_minor)->toBe(0)
        ->and($period->commission_minor)->toBe(0);
});

it('keeps a late (within 24h) cancellation billable', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    // Appointment one hour from now → cancelling now is a late (<24h) cancellation.
    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 400000, '2026-06-15 13:00:00');

    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled, null, 'no show risk');

    $item = BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole();
    expect($item->state)->toBe(CommissionItemState::Billable);
    expect(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->turnover_minor)->toBe(400000);
});

it('applies the raised rate when a rate-raising integration is active', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    app(SetTenantFeature::class)($tenant, Feature::OnlinePayment, true);

    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 100000);

    $item = BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole();
    expect($item->rate_bps)->toBe(150);
    // 1.5% of 100000 = 1500.
    expect(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->commission_minor)->toBe(1500);
});

it('snapshots the rate per booking so a mid-month integration toggle is not retroactive', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();

    // First booking becomes billable with no integration → 100 bps.
    $early = ledgerBooking($tenant, BookingStatus::Confirmed, 100000);

    // Turn the integration on mid-month; a later booking gets 150 bps.
    app(SetTenantFeature::class)($tenant, Feature::OnlinePayment, true);
    Carbon::setTestNow('2026-06-20 12:00:00');
    $late = ledgerBooking($tenant, BookingStatus::Confirmed, 100000);

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $early->id)->sole()->rate_bps)->toBe(100)
        ->and(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $late->id)->sole()->rate_bps)->toBe(150);
});

it('freezes the rate snapshot when a billable booking later changes status', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 100000);

    // Enable the integration only after it became billable, then complete it.
    app(SetTenantFeature::class)($tenant, Feature::OnlinePayment, true);
    app(ChangeBookingStatus::class)($booking, BookingStatus::Completed);

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole()->rate_bps)->toBe(100);
});

it('keys the period by the tenant timezone, not UTC', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create(['timezone' => 'Europe/Budapest']);
    // 23:30 UTC on the last day of June is already 01:30 on 1 July in Budapest (+2).
    Carbon::setTestNow('2026-06-30 23:30:00');

    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 100000);

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole()->period)->toBe('2026-07');
});

it('is idempotent on the booking (unique ledger entry per booking)', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 100000);

    // Re-run the upsert directly, twice more.
    app(UpsertBookingCommissionItem::class)($booking->fresh());
    app(UpsertBookingCommissionItem::class)($booking->fresh());

    expect(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->count())->toBe(1);
});

it('recomputes the aggregate from the full ledger across several bookings', function () {
    commissionSetting(threshold: 1_000_000); // 10 000 Ft free threshold
    $tenant = Tenant::factory()->active()->create();

    ledgerBooking($tenant, BookingStatus::Confirmed, 800000);
    ledgerBooking($tenant, BookingStatus::Confirmed, 700000);

    $period = TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    // Turnover 1 500 000; above-threshold base 500 000; 1% = 5 000.
    expect($period->turnover_minor)->toBe(1_500_000)
        ->and($period->commission_minor)->toBe(5_000);
});

it('does not recompute a closed (invoiced) period', function () {
    commissionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = ledgerBooking($tenant, BookingStatus::Confirmed, 400000);

    // Freeze the period as if it had been invoiced.
    $period = TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    $period->status = BillingPeriodStatus::Invoiced;
    $period->save();

    // A later 24h+ cancellation must NOT touch the frozen aggregate OR rewrite the
    // invoiced ledger entry (§8.2) — corrections go to the current period (J6).
    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    $period->refresh();
    expect($period->turnover_minor)->toBe(400000)
        ->and($period->status)->toBe(BillingPeriodStatus::Invoiced)
        ->and(BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->id)->sole()->state)
        ->toBe(CommissionItemState::Billable);
});

it('isolates ledger entries between tenants and 404s cross-tenant lookups', function () {
    // Build ledger rows directly via the factory; silence only the lifecycle
    // events (not the model's boot hooks) so the factory's own Booking creation
    // does not also write a competing ledger entry.
    Event::fake([BookingCreated::class, BookingStatusChanged::class]);

    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $tenants = app(TenantManager::class);

    $tenants->set($a);
    $aItem = BookingCommissionItem::factory()->create(['tenant_id' => $a->id]);

    $tenants->set($b);
    BookingCommissionItem::factory()->create(['tenant_id' => $b->id]);

    // From B's context A's row is invisible and not findable (route layer → 404).
    expect(BookingCommissionItem::query()->pluck('tenant_id')->all())->toBe([$b->id])
        ->and(BookingCommissionItem::find($aItem->getKey()))->toBeNull();
});
