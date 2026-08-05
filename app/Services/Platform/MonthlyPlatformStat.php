<?php

declare(strict_types=1);

namespace App\Services\Platform;

/**
 * One calendar month of the platform's growth series (SLO-138). Months are drawn
 * in the platform's own timezone (`config('app.timezone')`), not in any tenant's
 * — a platform total assembled from overlapping tenant-local months would count
 * the boundary twice.
 *
 * `tenantsAtStart` is the churn denominator: the tenants that were alive when the
 * month opened and therefore could have left it. A month nobody could leave has
 * no churn rate — `churnRateBps` is null, not zero, the same way an unscheduled
 * resource has no utilisation (SLO-137).
 */
final readonly class MonthlyPlatformStat
{
    public function __construct(
        /** The YYYY-MM key, in the platform timezone. */
        public string $period,
        public int $signups,
        public int $churned,
        /** Signups less churn — the month's net logo movement. */
        public int $netChange,
        public int $tenantsAtStart,
        public int $tenantsAtEnd,
        /** Churn as basis points of `tenantsAtStart`; null when nobody was at risk. */
        public ?int $churnRateBps,
        /** Bookings created platform-wide during the month, every tenant and status. */
        public int $bookings,
        /** False for the month still running: its figures are partial by definition. */
        public bool $complete,
    ) {}
}
