<?php

use App\Enums\BillingPeriodStatus;
use App\Models\Booking;
use App\Models\CommissionSetting;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// superUrl() and superAdmin() live in tests/Pest.php.

/*
 * Superadmin platform statistics (SLO-138, the superadmin half of SLO-45): the
 * tenant lifecycle breakdown, the monthly signup / churn / booking series and
 * the turnover distribution across tenants. Covers the access control, the
 * arithmetic against hand-checked figures, and the edge cases that make the
 * numbers lie if they are wrong — the undefined churn denominator, the running
 * month, and the archived-tenant timeline.
 */

beforeEach(function () {
    // A Wednesday mid-month, so "the running month" is genuinely partial and the
    // previous month is the newest complete one. The platform clock is UTC.
    Carbon::setTestNow('2026-07-15 09:00:00');

    CommissionSetting::factory()->create([
        'free_threshold_minor' => 1_000_000,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01 00:00:00'),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A tenant that signed up at $createdAt and, optionally, was archived at $deletedAt. */
function platformTenant(string $createdAt, ?string $deletedAt = null, array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($attributes);

    // created_at is not fillable and the factory stamps "now"; the timeline is
    // the whole point here, so it is written directly.
    $tenant->forceFill([
        'created_at' => Carbon::parse($createdAt),
        'deleted_at' => $deletedAt !== null ? Carbon::parse($deletedAt) : null,
    ])->saveQuietly();

    return $tenant->refresh();
}

/** A booking of the tenant created at the given instant (its own same-tenant service). */
function platformBooking(Tenant $tenant, string $createdAt): Booking
{
    $service = Service::factory()->forTenant($tenant)->create();

    $booking = Booking::factory()->forTenant($tenant)->create(['service_id' => $service->id]);

    $booking->forceFill(['created_at' => Carbon::parse($createdAt)])->saveQuietly();

    return $booking;
}

/** A billing-period aggregate row for the July period under test. */
function platformPeriodRow(Tenant $tenant, array $attributes): TenantBillingPeriod
{
    return TenantBillingPeriod::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'period' => '2026-07',
    ], $attributes));
}

// --- Access control ---

it('is closed to a tenant user', function () {
    $tenant = Tenant::factory()->active()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->get(superUrl('/statistics'))
        ->assertForbidden();
});

it('is closed to a guest', function () {
    $this->get(superUrl('/statistics'))->assertRedirect();
});

it('denies the platform statistics to a tenant user at the policy level', function () {
    $tenant = Tenant::factory()->active()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    expect($user->can('viewGlobalStatistics', Tenant::class))->toBeFalse();
    expect(superAdmin()->can('viewGlobalStatistics', Tenant::class))->toBeTrue();
});

// --- Lifecycle ---

it('breaks the tenant base down by lifecycle status', function () {
    Tenant::factory()->trial()->count(3)->create();
    Tenant::factory()->active()->count(2)->create();
    Tenant::factory()->suspended()->create();
    // Archiving soft-deletes, so an archived tenant is only visible withTrashed.
    $archived = Tenant::factory()->archived()->create();
    $archived->delete();

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Statistics')
            ->where('statistics.trial_tenants', 3)
            ->where('statistics.active_tenants', 2)
            ->where('statistics.suspended_tenants', 1)
            ->where('statistics.archived_tenants', 1)
            ->where('statistics.total_tenants', 7)
        );
});

// --- Growth & churn ---

it('reports signups, churn and the reconstructed tenant counts per month', function () {
    // Two tenants from before the window's tail: alive the whole time.
    platformTenant('2026-01-10 08:00:00');
    platformTenant('2026-02-03 08:00:00');
    // Signed up in May, archived in June — one churn event in June.
    platformTenant('2026-05-04 08:00:00', '2026-06-20 08:00:00');
    // Signed up in June, still alive.
    platformTenant('2026-06-11 08:00:00');

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?months=6'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            // 6 months ending with the running July: 02..07.
            $page->where('statistics.months', 6)
                ->has('statistics.series', 6)
                ->where('statistics.series.0.period', '2026-02')
                ->where('statistics.series.5.period', '2026-07')
                ->where('statistics.series.5.complete', false)
                ->where('statistics.series.4.complete', true);

            // February: one signup (the Feb tenant); the January tenant is the
            // only one at risk, and nobody left.
            $page->where('statistics.series.0.signups', 1)
                ->where('statistics.series.0.churned', 0)
                ->where('statistics.series.0.tenants_at_start', 1)
                ->where('statistics.series.0.tenants_at_end', 2)
                ->where('statistics.series.0.churn_rate_bps', 0);

            // May: the third tenant signs up. Two at the open, three at the close.
            $page->where('statistics.series.3.period', '2026-05')
                ->where('statistics.series.3.signups', 1)
                ->where('statistics.series.3.tenants_at_start', 2)
                ->where('statistics.series.3.tenants_at_end', 3);

            // June: one signup, one archive. 3 alive at the open → 1/3 churn.
            $page->where('statistics.series.4.period', '2026-06')
                ->where('statistics.series.4.signups', 1)
                ->where('statistics.series.4.churned', 1)
                ->where('statistics.series.4.net_change', 0)
                ->where('statistics.series.4.tenants_at_start', 3)
                ->where('statistics.series.4.tenants_at_end', 3)
                ->where('statistics.series.4.churn_rate_bps', 3_333);

            // Window totals and the headline: June is the newest complete month.
            $page->where('statistics.signups_total', 3)
                ->where('statistics.churned_total', 1)
                ->where('statistics.headline_churn_period', '2026-06')
                ->where('statistics.headline_churn_rate_bps', 3_333);
        });
});

it('leaves the churn rate undefined when nobody was at risk', function () {
    // Everyone signed up inside the running month: no month in the window opened
    // with a tenant that could have left it.
    platformTenant('2026-07-02 08:00:00');

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?months=6'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.series.5.tenants_at_start', 0)
            // Undefined, not 0% — nobody could have churned.
            ->where('statistics.series.5.churn_rate_bps', null)
            ->where('statistics.headline_churn_period', null)
            ->where('statistics.headline_churn_rate_bps', null)
        );
});

it('keeps the running month out of the headline churn rate', function () {
    // A tenant alive since January, plus one archived in the running July. If the
    // partial month were eligible it would headline with July's 50%.
    platformTenant('2026-01-10 08:00:00');
    platformTenant('2026-01-11 08:00:00', '2026-07-03 08:00:00');
    // June is complete and had no churn at all.

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?months=6'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.series.5.period', '2026-07')
            ->where('statistics.series.5.churned', 1)
            ->where('statistics.series.5.churn_rate_bps', 5_000)
            // The headline falls back to June, the newest month that finished.
            ->where('statistics.headline_churn_period', '2026-06')
            ->where('statistics.headline_churn_rate_bps', 0)
        );
});

it('counts a tenant that signed up and left inside the same month only as churn', function () {
    platformTenant('2026-01-10 08:00:00');
    platformTenant('2026-06-05 08:00:00', '2026-06-25 08:00:00');

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?months=6'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.series.4.period', '2026-06')
            ->where('statistics.series.4.signups', 1)
            ->where('statistics.series.4.churned', 1)
            // The denominator is the opening count, so a same-month signup never
            // dilutes (or inflates) the rate it is measured against.
            ->where('statistics.series.4.tenants_at_start', 1)
            ->where('statistics.series.4.churn_rate_bps', 10_000)
            ->where('statistics.series.4.tenants_at_end', 1)
        );
});

// --- Booking volume ---

it('counts platform bookings per month across every tenant', function () {
    $a = platformTenant('2026-01-10 08:00:00');
    $b = platformTenant('2026-01-11 08:00:00');

    platformBooking($a, '2026-06-02 10:00:00');
    platformBooking($b, '2026-06-28 10:00:00');
    platformBooking($a, '2026-07-01 10:00:00');
    // Outside the 6-month window (which opens on 2026-02-01): must not count.
    platformBooking($b, '2026-01-15 10:00:00');

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?months=6'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.series.4.period', '2026-06')
            ->where('statistics.series.4.bookings', 2)
            ->where('statistics.series.5.period', '2026-07')
            ->where('statistics.series.5.bookings', 1)
            ->where('statistics.series.0.bookings', 0)
            ->where('statistics.bookings_total', 3)
        );
});

it('places a booking made at the month boundary in exactly one month', function () {
    $tenant = platformTenant('2026-01-10 08:00:00');

    // The last instant of June and the first of July.
    platformBooking($tenant, '2026-06-30 23:59:59');
    platformBooking($tenant, '2026-07-01 00:00:00');

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?months=6'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.series.4.bookings', 1)
            ->where('statistics.series.5.bookings', 1)
            ->where('statistics.bookings_total', 2)
        );
});

it('counts the booking series in one query rather than one per month', function () {
    $tenant = platformTenant('2026-01-10 08:00:00');
    platformBooking($tenant, '2026-06-02 10:00:00');

    DB::enableQueryLog();

    $this->actingAs(superAdmin())->get(superUrl('/statistics?months=24'))->assertOk();

    $bookingQueries = collect(DB::getRawQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['raw_query'], 'from "bookings"'))
        ->count();

    DB::disableQueryLog();

    // 24 months, still a single aggregate — the conditional sums live in one
    // statement, so the window length never multiplies the round trips.
    expect($bookingQueries)->toBe(1);
});

// --- Turnover distribution ---

it('spreads tenants into turnover bands anchored on the free threshold', function () {
    // Threshold = 1 000 000 minor. Bands: <=1x, 1-2x, 2-5x, >5x.
    $a = platformTenant('2026-01-10 08:00:00');
    $b = platformTenant('2026-01-11 08:00:00');
    $c = platformTenant('2026-01-12 08:00:00');
    $d = platformTenant('2026-01-13 08:00:00');
    // Operational, no turnover at all: no period row.
    platformTenant('2026-01-14 08:00:00');

    // Exactly on the threshold: the commission model charges nothing here, so it
    // belongs in the "not paying yet" band, not the one above it.
    platformPeriodRow($a, ['turnover_minor' => 1_000_000, 'commission_minor' => 0]);
    platformPeriodRow($b, ['turnover_minor' => 1_500_000, 'commission_minor' => 10_000]);
    platformPeriodRow($c, ['turnover_minor' => 4_000_000, 'commission_minor' => 40_000]);
    platformPeriodRow($d, ['turnover_minor' => 9_000_000, 'commission_minor' => 90_000]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?period=2026-07'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.configured', true)
            ->where('statistics.free_threshold_minor', 1_000_000)
            ->has('statistics.turnover_bands', 4)
            ->where('statistics.turnover_bands.0.key', 'up_to_1x')
            ->where('statistics.turnover_bands.0.tenants', 1)
            ->where('statistics.turnover_bands.0.turnover_minor', 1_000_000)
            ->where('statistics.turnover_bands.0.commission_minor', 0)
            ->where('statistics.turnover_bands.1.key', 'up_to_2x')
            ->where('statistics.turnover_bands.1.tenants', 1)
            ->where('statistics.turnover_bands.1.commission_minor', 10_000)
            ->where('statistics.turnover_bands.2.key', 'up_to_5x')
            ->where('statistics.turnover_bands.2.tenants', 1)
            ->where('statistics.turnover_bands.2.turnover_minor', 4_000_000)
            ->where('statistics.turnover_bands.3.key', 'above_5x')
            ->where('statistics.turnover_bands.3.tenants', 1)
            ->where('statistics.turnover_bands.3.turnover_minor', 9_000_000)
            ->where('statistics.turnover_bands.3.to_minor', null)
            // The fifth tenant is operational but turned over nothing.
            ->where('statistics.no_turnover_tenants', 1)
        );
});

it('bills a voided period for nothing in its band', function () {
    $tenant = platformTenant('2026-01-10 08:00:00');

    platformPeriodRow($tenant, [
        'turnover_minor' => 4_000_000,
        'commission_minor' => 40_000,
        'status' => BillingPeriodStatus::Void,
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?period=2026-07'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The turnover happened and still counts as volume...
            ->where('statistics.turnover_bands.2.tenants', 1)
            ->where('statistics.turnover_bands.2.turnover_minor', 4_000_000)
            // ...but a voided period is invoiced for nothing (docs/10 §6.5).
            ->where('statistics.turnover_bands.2.commission_minor', 0)
        );
});

it('nets a credited period down inside its band, never below zero', function () {
    $a = platformTenant('2026-01-10 08:00:00');
    $b = platformTenant('2026-01-11 08:00:00');

    platformPeriodRow($a, [
        'turnover_minor' => 4_000_000,
        'commission_minor' => 40_000,
        'correction_minor' => -15_000,
    ]);
    // A credit bigger than the month's own commission: the remainder carries over
    // to the next period (§6.5), it is not negative revenue here.
    platformPeriodRow($b, [
        'turnover_minor' => 3_000_000,
        'commission_minor' => 30_000,
        'correction_minor' => -50_000,
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?period=2026-07'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.turnover_bands.2.tenants', 2)
            ->where('statistics.turnover_bands.2.commission_minor', 25_000)
        );
});

it('reports no bands at all when there is no pricing model', function () {
    CommissionSetting::query()->delete();
    platformTenant('2026-01-10 08:00:00');

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.configured', false)
            // Null, not empty bands: without a threshold they have no anchor.
            ->where('statistics.turnover_bands', null)
            ->where('statistics.free_threshold_minor', null)
        );
});

// --- Filters ---

it('rejects a window length that is not on the allow-list', function () {
    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?months=999'))
        ->assertSessionHasErrors('months');
});

it('rejects a malformed period', function () {
    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics?period=2026-7-1'))
        ->assertSessionHasErrors('period');
});

it('defaults to the current period over twelve months', function () {
    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.period', '2026-07')
            ->where('statistics.months', 12)
            ->has('statistics.series', 12)
            ->where('statistics.series.11.period', '2026-07')
            ->where('statistics.series.0.period', '2025-08')
            ->where('filters.period', null)
            ->where('filters.months', null)
        );
});
