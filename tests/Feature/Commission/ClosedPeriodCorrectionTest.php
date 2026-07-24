<?php

use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Commission\GenerateCommissionInvoice;
use App\Actions\Commission\RecomputeTenantPeriod;
use App\Actions\Commission\RecordClosedPeriodCorrection;
use App\Enums\BillingPeriodStatus;
use App\Enums\BookingStatus;
use App\Enums\CommissionCorrectionType;
use App\Enums\CommissionItemState;
use App\Models\Booking;
use App\Models\BookingCommissionItem;
use App\Models\CommissionCorrection;
use App\Models\CommissionInvoice;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;

/*
 * Retroactive change to an already-invoiced period (SLO-119, docs/10 §8.2/§15.5):
 * the closed period is never rewritten — the difference is credited to the
 * tenant's current open period and reduces the next monthly invoice.
 *
 * The credit is the difference the change makes to the *whole* closed month,
 * replayed through §2.3, which is what keeps it exact under the free threshold
 * and the monthly cap. Covers the credit itself, the untouched closed period,
 * the threshold/cap cases where nothing is owed back, repeated changes, the
 * no-retro-charge rule, invoicing the net, the carry-over of an unabsorbed
 * credit, and tenant isolation.
 */

/** The instant every test runs at: mid-July, so June is a closed past period. */
const CORRECTION_NOW = '2026-07-16 09:00:00';

beforeEach(function () {
    Carbon::setTestNow(CORRECTION_NOW);
});

afterEach(function () {
    Carbon::setTestNow();
    app(TenantManager::class)->forget();
});

function correctionSetting(int $threshold = 0, ?int $cap = null): CommissionSetting
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

/**
 * A confirmed booking that becomes billable *inside* the given period — the clock
 * is wound back so the real lifecycle listener writes its ledger entry there,
 * exactly as it happened that month.
 */
function correctionBooking(Tenant $tenant, string $period, int $amount, int $day = 10): Booking
{
    Carbon::setTestNow(Carbon::parse("{$period}-{$day} 12:00:00"));

    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'price_minor' => $amount,
        'starts_at' => '2026-09-01 10:00:00',
        'ends_at' => '2026-09-01 11:00:00',
    ]);

    Carbon::setTestNow(CORRECTION_NOW);

    return $booking;
}

/** Finalise a period from its ledger and freeze it as invoiced. */
function correctionClosePeriod(Tenant $tenant, string $period): TenantBillingPeriod
{
    $aggregate = app(RecomputeTenantPeriod::class)($tenant->getKey(), $period);
    assert($aggregate instanceof TenantBillingPeriod);

    $aggregate->status = BillingPeriodStatus::Invoiced;
    $aggregate->save();

    return $aggregate;
}

function correctionLedgerItem(Booking $booking): BookingCommissionItem
{
    return BookingCommissionItem::withoutGlobalScopes()->where('booking_id', $booking->getKey())->sole();
}

/**
 * @return list<CommissionCorrection>
 */
function correctionRows(Tenant $tenant): array
{
    return CommissionCorrection::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->orderBy('id')
        ->get()
        ->all();
}

// --- The credit itself ---

it('credits the open period when a booking from an invoiced period is cancelled early', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    $closed = correctionClosePeriod($tenant, '2026-06');

    // 1% of 400 000 = 4 000 was invoiced for June.
    expect($closed->commission_minor)->toBe(4_000);

    // Cancelled in July, far outside the 24h window → commission-free (§3.1).
    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    $corrections = correctionRows($tenant);
    expect($corrections)->toHaveCount(1);
    expect($corrections[0]->type)->toBe(CommissionCorrectionType::BookingAdjustment)
        ->and($corrections[0]->booking_id)->toBe($booking->id)
        ->and($corrections[0]->source_period)->toBe('2026-06')
        ->and($corrections[0]->period)->toBe('2026-07')
        ->and($corrections[0]->commission_delta_minor)->toBe(-4_000)
        ->and($corrections[0]->corrected_state)->toBe(CommissionItemState::Removed)
        ->and($corrections[0]->currency)->toBe('HUF');

    $july = TenantBillingPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)->where('period', '2026-07')->sole();
    expect($july->correction_minor)->toBe(-4_000)
        ->and($july->commission_minor)->toBe(0);
});

it('leaves the invoiced period and its frozen ledger entry untouched', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    $closed = correctionClosePeriod($tenant, '2026-06');

    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    $closed->refresh();
    expect($closed->turnover_minor)->toBe(400_000)
        ->and($closed->commission_minor)->toBe(4_000)
        ->and($closed->correction_minor)->toBe(0)
        ->and($closed->status)->toBe(BillingPeriodStatus::Invoiced)
        ->and(correctionLedgerItem($booking)->state)->toBe(CommissionItemState::Billable)
        ->and(correctionLedgerItem($booking)->amount_minor)->toBe(400_000);
});

// --- Nothing to credit: the change did not move the closed month's commission ---

it('credits nothing when the cancelled booking stayed below the free threshold', function () {
    // The month never charged commission, so there is nothing to hand back —
    // a per-booking "share" would have invented one.
    correctionSetting(threshold: 1_000_000);
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    $closed = correctionClosePeriod($tenant, '2026-06');

    expect($closed->commission_minor)->toBe(0);

    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    expect(correctionRows($tenant))->toBeEmpty();
});

it('credits nothing when the closed period was capped and stays capped', function () {
    // Two 1 000 000 bookings accrue 20 000 raw, capped at 5 000. Dropping one
    // still leaves 10 000 raw — above the cap, so the invoice was correct.
    correctionSetting(cap: 5_000);
    $tenant = Tenant::factory()->active()->create();
    $first = correctionBooking($tenant, '2026-06', 1_000_000, day: 10);
    correctionBooking($tenant, '2026-06', 1_000_000, day: 11);
    $closed = correctionClosePeriod($tenant, '2026-06');

    expect($closed->commission_minor)->toBe(5_000)
        ->and($closed->cap_reached)->toBeTrue();

    app(ChangeBookingStatus::class)($first, BookingStatus::Canceled);

    expect(correctionRows($tenant))->toBeEmpty();
});

it('credits only the marginal difference above the threshold', function () {
    // Threshold 1 000 000: two 800 000 bookings turn over 1 600 000, of which
    // 600 000 is billable → 6 000. Dropping the second leaves 800 000 → 0.
    correctionSetting(threshold: 1_000_000);
    $tenant = Tenant::factory()->active()->create();
    correctionBooking($tenant, '2026-06', 800_000, day: 10);
    $second = correctionBooking($tenant, '2026-06', 800_000, day: 11);
    $closed = correctionClosePeriod($tenant, '2026-06');

    expect($closed->commission_minor)->toBe(6_000);

    app(ChangeBookingStatus::class)($second, BookingStatus::Canceled);

    $corrections = correctionRows($tenant);
    expect($corrections)->toHaveCount(1)
        ->and($corrections[0]->commission_delta_minor)->toBe(-6_000);
});

// --- Price changes and repeated corrections ---

it('credits the difference when an invoiced booking is repriced downwards', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    correctionClosePeriod($tenant, '2026-06');

    // A price edit does not fire a booking lifecycle event today, so drive the
    // mechanism directly — this is the contract the price-edit path will use.
    app(RecordClosedPeriodCorrection::class)(
        $tenant,
        correctionLedgerItem($booking),
        250_000,
        CommissionItemState::Billable,
    );

    $corrections = correctionRows($tenant);
    // 1% of 400 000 → 1% of 250 000 = 4 000 → 2 500.
    expect($corrections)->toHaveCount(1)
        ->and($corrections[0]->commission_delta_minor)->toBe(-1_500)
        ->and($corrections[0]->corrected_amount_minor)->toBe(250_000)
        ->and($corrections[0]->corrected_state)->toBe(CommissionItemState::Billable);
});

it('measures a second change against the reality already credited', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    correctionClosePeriod($tenant, '2026-06');
    $item = correctionLedgerItem($booking);

    app(RecordClosedPeriodCorrection::class)($tenant, $item, 250_000, CommissionItemState::Billable);
    app(RecordClosedPeriodCorrection::class)($tenant, $item, 100_000, CommissionItemState::Billable);

    $corrections = correctionRows($tenant);
    // -1 500 then a further -1 500 (2 500 → 1 000), never the full 4 000 twice.
    expect($corrections)->toHaveCount(2)
        ->and(array_map(fn (CommissionCorrection $c): int => $c->commission_delta_minor, $corrections))
        ->toBe([-1_500, -1_500]);

    $july = TenantBillingPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)->where('period', '2026-07')->sole();
    expect($july->correction_minor)->toBe(-3_000);
});

it('does not credit twice when the lifecycle replays the same change', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    correctionClosePeriod($tenant, '2026-06');

    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);
    // The listener runs on every transition; a repeat must be a no-op.
    app(RecordClosedPeriodCorrection::class)(
        $tenant,
        correctionLedgerItem($booking),
        $booking->price_minor,
        CommissionItemState::Removed,
    );

    expect(correctionRows($tenant))->toHaveCount(1);
});

it('never retro-charges a closed period when the change raises its commission', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    correctionClosePeriod($tenant, '2026-06');
    $item = correctionLedgerItem($booking);

    app(RecordClosedPeriodCorrection::class)($tenant, $item, 100_000, CommissionItemState::Billable);
    // Back up to — and beyond — the invoiced price: no charge, and the credit stays.
    app(RecordClosedPeriodCorrection::class)($tenant, $item, 900_000, CommissionItemState::Billable);

    $corrections = correctionRows($tenant);
    expect($corrections)->toHaveCount(1)
        ->and($corrections[0]->commission_delta_minor)->toBe(-3_000);
});

it('lands the credit in the next period when the current one is already closed', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    correctionClosePeriod($tenant, '2026-06');

    // July invoiced early (a correction can arrive after its own month closed).
    correctionBooking($tenant, '2026-07', 100_000, day: 2);
    correctionClosePeriod($tenant, '2026-07');

    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    $corrections = correctionRows($tenant);
    expect($corrections)->toHaveCount(1)
        ->and($corrections[0]->period)->toBe('2026-08');
});

// --- Invoicing the net ---

it('nets the credit off the next invoice and charges VAT on the net', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    correctionClosePeriod($tenant, '2026-06');

    // July's own turnover: 1% of 1 000 000 = 10 000.
    correctionBooking($tenant, '2026-07', 1_000_000, day: 5);
    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    $invoice = app(GenerateCommissionInvoice::class)($tenant->getKey(), '2026-07');

    expect($invoice)->toBeInstanceOf(CommissionInvoice::class)
        ->and($invoice->correction_minor)->toBe(-4_000)
        // 10 000 − 4 000 = 6 000 net, 27% = 1 620.
        ->and($invoice->commission_net_minor)->toBe(6_000)
        ->and($invoice->vat_minor)->toBe(1_620)
        ->and($invoice->total_gross_minor)->toBe(7_620);
});

it('voids the period and carries an unabsorbed credit to the next one', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 1_000_000);
    correctionClosePeriod($tenant, '2026-06');

    // July accrues 1 000; the June credit is 10 000.
    correctionBooking($tenant, '2026-07', 100_000, day: 5);
    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    expect(app(GenerateCommissionInvoice::class)($tenant->getKey(), '2026-07'))->toBeNull();

    $july = TenantBillingPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)->where('period', '2026-07')->sole();
    expect($july->status)->toBe(BillingPeriodStatus::Void);

    $carry = CommissionCorrection::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', CommissionCorrectionType::CarryOver->value)
        ->sole();
    expect($carry->period)->toBe('2026-08')
        ->and($carry->source_period)->toBe('2026-07')
        // 1 000 earned − 10 000 credited = −9 000 still owed to the tenant.
        ->and($carry->commission_delta_minor)->toBe(-9_000)
        ->and($carry->booking_id)->toBeNull()
        ->and($carry->currency)->toBe('HUF');

    $august = TenantBillingPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)->where('period', '2026-08')->sole();
    expect($august->correction_minor)->toBe(-9_000);
});

it('rolls an unused credit on again through a month with no turnover', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 1_000_000);
    correctionClosePeriod($tenant, '2026-06');
    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    // July earns nothing, so the whole 10 000 credit moves on — twice.
    app(GenerateCommissionInvoice::class)($tenant->getKey(), '2026-07');
    app(GenerateCommissionInvoice::class)($tenant->getKey(), '2026-08');

    $september = TenantBillingPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)->where('period', '2026-09')->sole();
    expect($september->correction_minor)->toBe(-10_000)
        ->and($september->status)->toBe(BillingPeriodStatus::Open);
});

it('keeps a fully absorbed credit from carrying anything forward', function () {
    correctionSetting();
    $tenant = Tenant::factory()->active()->create();
    $booking = correctionBooking($tenant, '2026-06', 400_000);
    correctionClosePeriod($tenant, '2026-06');

    // July accrues exactly the 4 000 credited back.
    correctionBooking($tenant, '2026-07', 400_000, day: 5);
    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    expect(app(GenerateCommissionInvoice::class)($tenant->getKey(), '2026-07'))->toBeNull()
        ->and(CommissionCorrection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', CommissionCorrectionType::CarryOver->value)
            ->exists())->toBeFalse();
});

// --- Tenant isolation ---

it('isolates corrections between tenants', function () {
    correctionSetting();
    $a = Tenant::factory()->active()->create();
    $b = Tenant::factory()->active()->create();

    $booking = correctionBooking($a, '2026-06', 400_000);
    correctionClosePeriod($a, '2026-06');
    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled);

    $tenants = app(TenantManager::class);

    $tenants->set($b);
    expect(CommissionCorrection::query()->count())->toBe(0)
        ->and(TenantBillingPeriod::query()->where('period', '2026-07')->exists())->toBeFalse();

    $tenants->set($a);
    expect(CommissionCorrection::query()->count())->toBe(1)
        ->and(CommissionCorrection::query()->sole()->tenant_id)->toBe($a->id);
});
