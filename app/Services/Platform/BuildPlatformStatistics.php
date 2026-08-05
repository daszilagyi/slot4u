<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Enums\BillingPeriodStatus;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Services\Commission\BillingPeriodClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the platform-wide business statistics for the superadmin (SLO-138, the
 * superadmin half of SLO-45): the tenant lifecycle breakdown, the monthly
 * signup / churn / booking series, and the turnover distribution across tenants.
 *
 * Every query drops the BelongsToTenant global scope — there is no bound tenant
 * in the superadmin context — and reads with the trashed rows included, because
 * archiving a tenant soft-deletes it (see ChangeTenantStatus) and an archived
 * tenant is precisely what churn is made of.
 *
 * ⚠️ **Churn is reconstructed from `deleted_at`, not from a status journal.**
 * The platform has no per-transition history table, and archiving is the only
 * terminal exit, so "the tenants archived during month M" is the honest measure
 * available. The consequence is worth knowing before trusting a historical
 * number: restoring an archived tenant clears its `deleted_at`, and its past
 * churn event silently disappears from the series. Suspension is deliberately
 * *not* churn — a suspended tenant is a delinquent one (docs/10 §6.6), which
 * dunning is meant to win back.
 *
 * Months are calendar months in the *platform's* timezone, not in each tenant's:
 * a platform total assembled from overlapping tenant-local months would count
 * the boundaries twice. The billing period (`YYYY-MM`) the distribution reports
 * on keeps its own tenant-local meaning — that key is written by the recompute
 * (docs/10 §5.4) and is only read back here.
 */
final class BuildPlatformStatistics
{
    private const FALLBACK_CURRENCY = 'HUF';

    /** How many months the growth series spans by default, the running one included. */
    public const DEFAULT_MONTHS = 12;

    /**
     * The turnover bands, as multiples of the commission free threshold: the
     * upper bound of each, the last one unbounded. Anchoring on the threshold
     * rather than on round amounts keeps "has not reached the paying line yet"
     * the meaning of the first band across pricing versions.
     *
     * @var array<string, int|null>
     */
    private const BAND_MULTIPLES = [
        'up_to_1x' => 1,
        'up_to_2x' => 2,
        'up_to_5x' => 5,
        'above_5x' => null,
    ];

    /**
     * The billable net of one period, floored at zero — the same expression the
     * commission statistics use (docs/10 §8.2, SLO-127): a credit larger than the
     * month's commission is not negative revenue, it carries over to the next
     * period. `CASE WHEN` rather than GREATEST/MAX keeps it portable across the
     * production MariaDB and the SQLite the suite runs on.
     */
    private const NET_EXPRESSION = <<<'SQL'
        CASE WHEN commission_minor + correction_minor > 0
            THEN commission_minor + correction_minor
            ELSE 0
        END
        SQL;

    public function __construct(
        private readonly BillingPeriodClock $clock,
    ) {}

    /**
     * @param  string|null  $period  The YYYY-MM month the turnover distribution reports on; null = the current one.
     * @param  int|null  $months  How many months the growth series spans; null = {@see self::DEFAULT_MONTHS}.
     */
    public function __invoke(?string $period = null, ?int $months = null): PlatformStatistics
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $period ??= $this->clock->currentPeriod($timezone);
        $months = $months ?? self::DEFAULT_MONTHS;

        $setting = $this->effectiveSetting($period, $timezone);

        // The tenant table is a platform-scale table (one row per customer
        // company), so the whole lifecycle and the whole growth series are
        // bucketed in PHP from a single projection. That keeps the month
        // boundaries in Carbon — portable date arithmetic the SQL dialects do
        // not agree on — instead of a GROUP BY neither MariaDB nor SQLite would
        // express the same way.
        $tenants = $this->tenantTimeline();

        $window = $this->monthWindow($timezone, $months);
        $bookingCounts = $this->bookingCountsByMonth($window);

        $series = $this->growthSeries($window, $tenants, $bookingCounts);
        $headline = $this->headlineChurn($series);

        $bands = $setting !== null
            ? $this->turnoverBands($period, $setting->free_threshold_minor)
            : null;

        $byStatus = $this->countByStatus($tenants);
        $operational = $byStatus[TenantStatus::Trial->value] + $byStatus[TenantStatus::Active->value];

        return new PlatformStatistics(
            period: $period,
            timezone: $timezone,
            months: $months,
            configured: $setting !== null,
            freeThresholdMinor: $setting?->free_threshold_minor,
            currency: $setting !== null ? $setting->currency : self::FALLBACK_CURRENCY,
            trialTenants: $byStatus[TenantStatus::Trial->value],
            activeTenants: $byStatus[TenantStatus::Active->value],
            suspendedTenants: $byStatus[TenantStatus::Suspended->value],
            archivedTenants: $byStatus[TenantStatus::Archived->value],
            totalTenants: $tenants->count(),
            series: $series,
            signupsTotal: array_sum(array_map(
                static fn (MonthlyPlatformStat $stat): int => $stat->signups,
                $series,
            )),
            churnedTotal: array_sum(array_map(
                static fn (MonthlyPlatformStat $stat): int => $stat->churned,
                $series,
            )),
            bookingsTotal: array_sum(array_map(
                static fn (MonthlyPlatformStat $stat): int => $stat->bookings,
                $series,
            )),
            headlineChurnPeriod: $headline?->period,
            headlineChurnRateBps: $headline?->churnRateBps,
            turnoverBands: $bands,
            noTurnoverTenants: max(0, $operational - $this->tenantsWithTurnover($period)),
        );
    }

    /**
     * The pricing version in force at the period's close, pinned exactly the way
     * the recompute pins it (docs/10 §6.4) so the band bounds match the threshold
     * that actually priced the month. Null = no pricing model configured yet.
     */
    private function effectiveSetting(string $period, string $timezone): ?CommissionSetting
    {
        return CommissionSetting::query()
            ->where('effective_from', '<=', $this->clock->referenceInstant($period, $timezone))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Every tenant's status plus the two instants that define its life: when it
     * signed up and when it was archived. Trashed rows included — an archived
     * tenant is soft-deleted and is the whole point of the churn series.
     *
     * @return Collection<int, Tenant>
     */
    private function tenantTimeline(): Collection
    {
        return Tenant::query()
            ->withTrashed()
            ->get(['id', 'status', 'created_at', 'deleted_at']);
    }

    /**
     * The lifecycle breakdown, every status present as a key so a status nobody
     * is in reads as an explicit zero rather than a missing tile.
     *
     * @param  Collection<int, Tenant>  $tenants
     * @return array<string, int>
     */
    private function countByStatus(Collection $tenants): array
    {
        $counts = [];

        foreach (TenantStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach ($tenants as $tenant) {
            $counts[$tenant->status->value]++;
        }

        return $counts;
    }

    /**
     * The month boundaries of the growth window, oldest first, as UTC instants.
     * Each month is `[start, end)` — a half-open range, so an event at midnight
     * belongs to exactly one month and never to both.
     *
     * @return list<array{period: string, start: Carbon, end: Carbon, complete: bool}>
     */
    private function monthWindow(string $timezone, int $months): array
    {
        $currentMonthStart = Carbon::now($timezone)->startOfMonth();

        $window = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $start = $currentMonthStart->copy()->subMonthsNoOverflow($offset);
            $end = $start->copy()->addMonthNoOverflow();

            $window[] = [
                'period' => $start->format('Y-m'),
                'start' => $start->copy()->utc(),
                'end' => $end->copy()->utc(),
                // Only the last month of the window is still running.
                'complete' => $offset > 0,
            ];
        }

        return $window;
    }

    /**
     * Bookings created per month across every tenant, in one query: a range scan
     * over the whole window with a conditional sum per month. The alternative —
     * grouping on a formatted month — has no portable spelling (`strftime` on
     * SQLite, `DATE_FORMAT` on MariaDB), and one query per month would be a
     * self-inflicted N+1.
     *
     * @param  list<array{period: string, start: Carbon, end: Carbon, complete: bool}>  $window
     * @return array<string, int> Month key => booking count.
     */
    private function bookingCountsByMonth(array $window): array
    {
        if ($window === []) {
            return [];
        }

        $expressions = [];
        $bindings = [];

        foreach ($window as $index => $month) {
            $expressions[] = "COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) as m{$index}";
            $bindings[] = $month['start'];
            $bindings[] = $month['end'];
        }

        $row = Booking::query()
            ->withoutGlobalScopes()
            ->where('created_at', '>=', $window[0]['start'])
            ->where('created_at', '<', $window[array_key_last($window)]['end'])
            ->selectRaw(implode(', ', $expressions), $bindings)
            ->toBase()
            ->first();

        $counts = [];

        foreach ($window as $index => $month) {
            $counts[$month['period']] = $row !== null ? (int) $row->{"m{$index}"} : 0;
        }

        return $counts;
    }

    /**
     * The month-by-month growth picture. A tenant is "alive" at an instant when
     * it was created before it and not yet archived at it; the churn denominator
     * is the alive count at the month's opening, so a tenant that both signed up
     * and left inside the same month never inflates the rate it is measured
     * against.
     *
     * @param  list<array{period: string, start: Carbon, end: Carbon, complete: bool}>  $window
     * @param  Collection<int, Tenant>  $tenants
     * @param  array<string, int>  $bookingCounts
     * @return list<MonthlyPlatformStat>
     */
    private function growthSeries(array $window, Collection $tenants, array $bookingCounts): array
    {
        return array_map(function (array $month) use ($tenants, $bookingCounts): MonthlyPlatformStat {
            $signups = 0;
            $churned = 0;
            $atStart = 0;
            $atEnd = 0;

            foreach ($tenants as $tenant) {
                $createdAt = $tenant->created_at;
                $deletedAt = $tenant->deleted_at;

                // A tenant with no creation timestamp cannot be placed on the
                // timeline at all; counting it would only distort the rate.
                if ($createdAt === null) {
                    continue;
                }

                if ($createdAt->gte($month['start']) && $createdAt->lt($month['end'])) {
                    $signups++;
                }

                if ($deletedAt !== null && $deletedAt->gte($month['start']) && $deletedAt->lt($month['end'])) {
                    $churned++;
                }

                if ($createdAt->lt($month['start']) && ($deletedAt === null || $deletedAt->gte($month['start']))) {
                    $atStart++;
                }

                if ($createdAt->lt($month['end']) && ($deletedAt === null || $deletedAt->gte($month['end']))) {
                    $atEnd++;
                }
            }

            return new MonthlyPlatformStat(
                period: $month['period'],
                signups: $signups,
                churned: $churned,
                netChange: $signups - $churned,
                tenantsAtStart: $atStart,
                tenantsAtEnd: $atEnd,
                // Nobody at risk means the rate is undefined, not zero.
                churnRateBps: $atStart > 0 ? (int) round($churned * 10_000 / $atStart) : null,
                bookings: $bookingCounts[$month['period']] ?? 0,
                complete: $month['complete'],
            );
        }, $window);
    }

    /**
     * The churn rate worth putting on a tile: the most recent month that has
     * actually finished. The running month has only lived part of its life, so
     * its rate is structurally too low and would read as good news every time it
     * is looked at.
     *
     * @param  list<MonthlyPlatformStat>  $series
     */
    private function headlineChurn(array $series): ?MonthlyPlatformStat
    {
        foreach (array_reverse($series) as $stat) {
            if ($stat->complete && $stat->churnRateBps !== null) {
                return $stat;
            }
        }

        return null;
    }

    /**
     * How the period's turnover is spread across tenants, in one aggregate query
     * per the same pattern as the commission statistics. Bands are half-open on
     * the low side (`> from`) and closed on the high side (`<= to`), so a tenant
     * sitting exactly on the free threshold lands in the "not paying yet" band —
     * which is where the commission model puts it (docs/10 §2.3).
     *
     * @return list<TurnoverBandStat>
     */
    private function turnoverBands(string $period, int $threshold): array
    {
        $net = self::NET_EXPRESSION;

        $expressions = [];
        $bindings = [];
        $bounds = [];
        $from = 0;

        foreach (self::BAND_MULTIPLES as $key => $multiple) {
            $to = $multiple !== null ? $threshold * $multiple : null;
            $bounds[$key] = ['from' => $from, 'to' => $to];

            $condition = $to !== null
                ? 'turnover_minor > ? AND turnover_minor <= ?'
                : 'turnover_minor > ?';

            $expressions[] = "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) as {$key}_tenants";
            $expressions[] = "COALESCE(SUM(CASE WHEN {$condition} THEN turnover_minor ELSE 0 END), 0) as {$key}_turnover";
            $expressions[] = "COALESCE(SUM(CASE WHEN ({$condition}) AND status <> ? THEN {$net} ELSE 0 END), 0) as {$key}_commission";

            // Three copies of the same bounds: one per conditional sum above.
            for ($copy = 0; $copy < 3; $copy++) {
                $bindings[] = $from;

                if ($to !== null) {
                    $bindings[] = $to;
                }

                // The voided period is billed for nothing (docs/10 §6.5) — only
                // the commission sum needs to know about it.
                if ($copy === 2) {
                    $bindings[] = BillingPeriodStatus::Void->value;
                }
            }

            $from = $to ?? $from;
        }

        $row = TenantBillingPeriod::query()
            ->withoutGlobalScopes()
            ->where('period', $period)
            ->selectRaw(implode(', ', $expressions), $bindings)
            ->toBase()
            ->first();

        return array_map(
            fn (string $key): TurnoverBandStat => new TurnoverBandStat(
                key: $key,
                fromMinor: $bounds[$key]['from'],
                toMinor: $bounds[$key]['to'],
                tenants: $row !== null ? (int) $row->{"{$key}_tenants"} : 0,
                turnoverMinor: $row !== null ? (int) $row->{"{$key}_turnover"} : 0,
                commissionMinor: $row !== null ? (int) $row->{"{$key}_commission"} : 0,
            ),
            array_keys(self::BAND_MULTIPLES),
        );
    }

    /**
     * Tenants that turned over anything at all in the period — the complement of
     * the "no turnover" tile.
     */
    private function tenantsWithTurnover(string $period): int
    {
        return TenantBillingPeriod::query()
            ->withoutGlobalScopes()
            ->where('period', $period)
            ->where('turnover_minor', '>', 0)
            ->count();
    }
}
