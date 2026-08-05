<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Super\PlatformStatisticsFilterRequest;
use App\Models\Tenant;
use App\Services\Platform\BuildPlatformStatistics;
use App\Services\Platform\MonthlyPlatformStat;
use App\Services\Platform\PlatformStatistics;
use App\Services\Platform\TurnoverBandStat;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Superadmin platform statistics (SLO-138, the superadmin half of SLO-45): the
 * tenant lifecycle, the monthly growth / churn / booking series and the turnover
 * distribution. The commission side of the same business — what slot4u earns —
 * lives on the dashboard {@see DashboardController}; this page is the traffic the
 * commission is levied on.
 *
 * Behind auth + ensure.superadmin (routes/admin.php), so there is no bound
 * tenant. The arithmetic is in {@see BuildPlatformStatistics}; the controller
 * only authorizes, resolves the filters and shapes the props.
 */
class StatisticsController extends Controller
{
    public function index(
        PlatformStatisticsFilterRequest $request,
        BuildPlatformStatistics $build,
    ): Response {
        Gate::authorize('viewGlobalStatistics', Tenant::class);

        $period = $request->validated('period');
        $months = $request->validated('months');
        $months = $months !== null ? (int) $months : null;

        $statistics = $build($period, $months);

        return Inertia::render('Super/Statistics', [
            'statistics' => $this->statisticsProps($statistics),
            // Echo back only what was asked for, so an untouched filter can fall
            // to its placeholder rather than pretending the default was typed.
            'filters' => ['period' => $period, 'months' => $months],
            'month_options' => PlatformStatisticsFilterRequest::ALLOWED_MONTHS,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statisticsProps(PlatformStatistics $statistics): array
    {
        return [
            'period' => $statistics->period,
            'timezone' => $statistics->timezone,
            'months' => $statistics->months,
            'configured' => $statistics->configured,
            'free_threshold_minor' => $statistics->freeThresholdMinor,
            'currency' => $statistics->currency,
            'trial_tenants' => $statistics->trialTenants,
            'active_tenants' => $statistics->activeTenants,
            'suspended_tenants' => $statistics->suspendedTenants,
            'archived_tenants' => $statistics->archivedTenants,
            'total_tenants' => $statistics->totalTenants,
            'signups_total' => $statistics->signupsTotal,
            'churned_total' => $statistics->churnedTotal,
            'bookings_total' => $statistics->bookingsTotal,
            'headline_churn_period' => $statistics->headlineChurnPeriod,
            'headline_churn_rate_bps' => $statistics->headlineChurnRateBps,
            'series' => array_map(
                fn (MonthlyPlatformStat $stat): array => [
                    'period' => $stat->period,
                    'signups' => $stat->signups,
                    'churned' => $stat->churned,
                    'net_change' => $stat->netChange,
                    'tenants_at_start' => $stat->tenantsAtStart,
                    'tenants_at_end' => $stat->tenantsAtEnd,
                    'churn_rate_bps' => $stat->churnRateBps,
                    'bookings' => $stat->bookings,
                    'complete' => $stat->complete,
                ],
                $statistics->series,
            ),
            'turnover_bands' => $statistics->turnoverBands === null ? null : array_map(
                fn (TurnoverBandStat $band): array => [
                    'key' => $band->key,
                    'from_minor' => $band->fromMinor,
                    'to_minor' => $band->toMinor,
                    'tenants' => $band->tenants,
                    'turnover_minor' => $band->turnoverMinor,
                    'commission_minor' => $band->commissionMinor,
                ],
                $statistics->turnoverBands,
            ),
            'no_turnover_tenants' => $statistics->noTurnoverTenants,
        ];
    }
}
