<?php

namespace App\Http\Controllers\Super;

use App\Console\Commands\DunningSweep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\CommissionStatisticsFilterRequest;
use App\Models\TenantBillingPeriod;
use App\Services\Commission\BuildCommissionStatistics;
use App\Services\Commission\CommissionStatistics;
use App\Services\Commission\OverdueInvoiceStat;
use App\Services\Commission\TopTenantStat;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Superadmin dashboard (SLO-123, docs/10 §10): the platform's commission
 * business view — monthly revenue (MRR proxy), the activation funnel, top
 * tenants by turnover, and the overdue invoices heading for suspension. This is
 * the landing's first real content; the navigation hub lives alongside it.
 *
 * Behind auth + ensure.superadmin (routes/admin.php), so there is no bound
 * tenant. The heavy lifting is in {@see BuildCommissionStatistics}; the
 * controller only authorizes, resolves the period filter and shapes the props.
 */
class DashboardController extends Controller
{
    public function index(
        CommissionStatisticsFilterRequest $request,
        BuildCommissionStatistics $build,
    ): Response {
        Gate::authorize('viewGlobal', TenantBillingPeriod::class);

        $period = $request->validated('period');
        $statistics = $build($period);

        return Inertia::render('Super/Dashboard', [
            'statistics' => $this->statisticsProps($statistics),
            // Echo back only what was typed so the input can fall to a placeholder
            // for the implicit current month.
            'filters' => ['period' => $period],
            'grace_days' => DunningSweep::GRACE_DAYS,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statisticsProps(CommissionStatistics $statistics): array
    {
        return [
            'period' => $statistics->period,
            'currency' => $statistics->currency,
            'configured' => $statistics->configured,
            'free_threshold_minor' => $statistics->freeThresholdMinor,
            'monthly_cap_minor' => $statistics->monthlyCapMinor,
            'turnover_total_minor' => $statistics->turnoverTotalMinor,
            'commission_accrued_minor' => $statistics->commissionAccruedMinor,
            'correction_total_minor' => $statistics->correctionTotalMinor,
            'commission_net_minor' => $statistics->commissionNetMinor,
            'commission_open_minor' => $statistics->commissionOpenMinor,
            'commission_invoiced_minor' => $statistics->commissionInvoicedMinor,
            'commission_paid_minor' => $statistics->commissionPaidMinor,
            'commission_overdue_minor' => $statistics->commissionOverdueMinor,
            'active_tenants' => $statistics->activeTenants,
            'tenants_with_turnover' => $statistics->tenantsWithTurnover,
            'paying_tenants' => $statistics->payingTenants,
            'stuck_tenants' => $statistics->stuckTenants,
            'cap_reached_tenants' => $statistics->capReachedTenants,
            'top_tenants' => array_map(
                fn (TopTenantStat $stat): array => [
                    'tenant_id' => $stat->tenantId,
                    'tenant_name' => $stat->tenantName,
                    'tenant_slug' => $stat->tenantSlug,
                    'tenant_status' => $stat->tenantStatus,
                    'turnover_minor' => $stat->turnoverMinor,
                    'commission_minor' => $stat->commissionMinor,
                    'correction_minor' => $stat->correctionMinor,
                    'net_minor' => $stat->netMinor,
                    'cap_reached' => $stat->capReached,
                ],
                $statistics->topTenants,
            ),
            'overdue_invoices' => array_map(
                fn (OverdueInvoiceStat $stat): array => [
                    'invoice_id' => $stat->invoiceId,
                    'tenant_id' => $stat->tenantId,
                    'tenant_name' => $stat->tenantName,
                    'tenant_slug' => $stat->tenantSlug,
                    'period' => $stat->period,
                    'total_gross_minor' => $stat->totalGrossMinor,
                    'currency' => $stat->currency,
                    'due_at' => $stat->dueAt->toIso8601String(),
                    'suspend_at' => $stat->suspendAt->toIso8601String(),
                    'days_until_suspension' => $stat->daysUntilSuspension,
                ],
                $statistics->overdueInvoices,
            ),
            'overdue_count' => $statistics->overdueCount,
            'overdue_total_minor' => $statistics->overdueTotalMinor,
        ];
    }
}
