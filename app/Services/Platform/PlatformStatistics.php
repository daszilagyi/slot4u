<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Commission\CommissionStatistics;

/**
 * The platform's own numbers for the superadmin (SLO-138, the second half of
 * SLO-45): where the tenant base stands right now, how it grew and churned month
 * by month, how much booking traffic the platform carries, and how turnover is
 * spread across tenants.
 *
 * This is the counterpart of {@see CommissionStatistics},
 * which answers "what did slot4u earn". Nothing here is money the platform
 * collects — it is the business the commission is levied on.
 */
final readonly class PlatformStatistics
{
    /**
     * @param  list<MonthlyPlatformStat>  $series  Oldest month first; the last entry is the running month.
     * @param  list<TurnoverBandStat>|null  $turnoverBands  Null when no pricing model is configured — the bands have no anchor.
     */
    public function __construct(
        /** The YYYY-MM the turnover distribution reports on. */
        public string $period,
        /** The platform timezone the months are drawn in. */
        public string $timezone,
        /** How many months the series covers, the running one included. */
        public int $months,
        public bool $configured,
        public ?int $freeThresholdMinor,
        public string $currency,
        // Lifecycle, as of now. Archived tenants are soft-deleted, so they are
        // counted with the trashed rows included.
        public int $trialTenants,
        public int $activeTenants,
        public int $suspendedTenants,
        public int $archivedTenants,
        /** Every tenant ever created, archived ones included. */
        public int $totalTenants,
        // Growth over the whole window.
        public array $series,
        public int $signupsTotal,
        public int $churnedTotal,
        public int $bookingsTotal,
        /**
         * The headline churn rate: the most recent *complete* month's, because
         * the running month has only lived part of its life and would read as an
         * artificially low rate. Null when no complete month is in the window or
         * nobody was at risk in it.
         */
        public ?string $headlineChurnPeriod,
        public ?int $headlineChurnRateBps,
        // Turnover distribution for `period`.
        public ?array $turnoverBands,
        /** Operational tenants with no turnover at all in the period. */
        public int $noTurnoverTenants,
    ) {}
}
