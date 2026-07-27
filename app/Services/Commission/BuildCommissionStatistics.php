<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Console\Commands\DunningSweep;
use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\CommissionInvoice;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use Illuminate\Support\Carbon;

/**
 * Builds the platform-wide commission statistics for the superadmin dashboard
 * (docs/10 §10): monthly commission revenue (MRR proxy), the activation funnel,
 * the top tenants by turnover, and the overdue invoices that are heading for a
 * suspension.
 *
 * Every query drops the BelongsToTenant global scope — there is no bound tenant
 * in the superadmin context (the J6/J7 pattern) — and reads the
 * `tenant_billing_periods` aggregate, which is the source of truth for a period
 * (§5.4), never the ledger. The revenue split by lifecycle uses conditional
 * sums so the whole month collapses into a single aggregate query, backed by the
 * `period` index added in SLO-123.
 *
 * Revenue is reported net of the credits a closed period's later change produced
 * (§8.2, SLO-127): the accrual is the gross figure, the billable net is what the
 * invoice would actually charge.
 */
final class BuildCommissionStatistics
{
    private const FALLBACK_CURRENCY = 'HUF';

    /** How many tenants to surface in the "top by turnover" table. */
    private const TOP_LIMIT = 10;

    /**
     * What one period would actually be invoiced for: its own commission less the
     * credits carried into it (§8.2), floored at zero. The floor is not a rounding
     * convenience — a credit bigger than the month's commission is not negative
     * revenue: closing such a period voids it and moves the remainder to the next
     * one (§6.5), where this same sum counts it again. Without the floor the
     * platform would book the credit twice. `CASE WHEN` rather than GREATEST/MAX
     * keeps it portable across MariaDB and the SQLite the suite runs on.
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
     * @param  string|null  $period  The YYYY-MM month to report on; null = the
     *                               month the platform is currently accruing into.
     */
    public function __invoke(?string $period = null): CommissionStatistics
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $period ??= $this->clock->currentPeriod($timezone);

        $setting = $this->effectiveSetting($period, $timezone);

        $aggregate = $this->periodAggregate($period);
        $withTurnover = (int) $aggregate->with_turnover;
        $paying = (int) $aggregate->paying;

        $overdue = $this->overdueInvoices();

        return new CommissionStatistics(
            period: $period,
            currency: $setting !== null ? $setting->currency : self::FALLBACK_CURRENCY,
            configured: $setting !== null,
            freeThresholdMinor: $setting?->free_threshold_minor,
            monthlyCapMinor: $setting?->monthly_cap_minor,
            turnoverTotalMinor: (int) $aggregate->turnover_total,
            commissionAccruedMinor: (int) $aggregate->commission_accrued,
            correctionTotalMinor: (int) $aggregate->correction_total,
            commissionNetMinor: (int) $aggregate->commission_net,
            commissionOpenMinor: (int) $aggregate->commission_open,
            commissionInvoicedMinor: (int) $aggregate->commission_invoiced,
            commissionPaidMinor: (int) $aggregate->commission_paid,
            commissionOverdueMinor: (int) $aggregate->commission_overdue,
            activeTenants: $this->operationalTenantCount(),
            tenantsWithTurnover: $withTurnover,
            payingTenants: $paying,
            // Turnover but no commission = below the free threshold, not yet paying.
            stuckTenants: max(0, $withTurnover - $paying),
            capReachedTenants: (int) $aggregate->cap_reached,
            topTenants: $this->topTenants($period),
            overdueInvoices: $overdue,
            overdueCount: count($overdue),
            overdueTotalMinor: array_sum(array_map(
                static fn (OverdueInvoiceStat $stat): int => $stat->totalGrossMinor,
                $overdue,
            )),
        );
    }

    /**
     * The commission settings version in force at the period's close, pinned the
     * same way the recompute pins it (§6.4) so the displayed threshold/cap match
     * what actually priced the month. Null = no pricing model configured yet.
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
     * One aggregate row for the whole month across every tenant: totals plus the
     * revenue split by billing-period lifecycle (open/invoiced/paid/overdue) and
     * the funnel tallies. Conditional sums keep it a single query (no N+1, no
     * per-status round trips).
     *
     * The net and the lifecycle sums report the *net* figure (commission less
     * credits), which is what the month is actually billed for. A voided period
     * falls into no bucket, so a stornoed invoice or a fully credited month adds
     * nothing to the revenue — while the gross accrual keeps counting it. The net
     * is summed on its own rather than by adding the four buckets up, so a future
     * sixth lifecycle status cannot silently drop out of the revenue total.
     */
    private function periodAggregate(string $period): object
    {
        $net = self::NET_EXPRESSION;

        $row = TenantBillingPeriod::query()
            ->withoutGlobalScopes()
            ->where('period', $period)
            ->selectRaw(<<<SQL
                COALESCE(SUM(turnover_minor), 0) as turnover_total,
                COALESCE(SUM(commission_minor), 0) as commission_accrued,
                COALESCE(SUM(correction_minor), 0) as correction_total,
                COALESCE(SUM(CASE WHEN turnover_minor > 0 THEN 1 ELSE 0 END), 0) as with_turnover,
                COALESCE(SUM(CASE WHEN status <> ? AND commission_minor + correction_minor > 0 THEN 1 ELSE 0 END), 0) as paying,
                COALESCE(SUM(CASE WHEN cap_reached = 1 THEN 1 ELSE 0 END), 0) as cap_reached,
                COALESCE(SUM(CASE WHEN status <> ? THEN {$net} ELSE 0 END), 0) as commission_net,
                COALESCE(SUM(CASE WHEN status = ? THEN {$net} ELSE 0 END), 0) as commission_open,
                COALESCE(SUM(CASE WHEN status = ? THEN {$net} ELSE 0 END), 0) as commission_invoiced,
                COALESCE(SUM(CASE WHEN status = ? THEN {$net} ELSE 0 END), 0) as commission_paid,
                COALESCE(SUM(CASE WHEN status = ? THEN {$net} ELSE 0 END), 0) as commission_overdue
                SQL, [
                BillingPeriodStatus::Void->value,  // paying
                BillingPeriodStatus::Void->value,  // commission_net
                BillingPeriodStatus::Open->value,
                BillingPeriodStatus::Invoiced->value,
                BillingPeriodStatus::Paid->value,
                BillingPeriodStatus::Overdue->value,
            ])
            ->toBase()
            ->first();

        // No rows for the month yet — everything reads as zero.
        return $row ?? (object) [
            'turnover_total' => 0,
            'commission_accrued' => 0,
            'correction_total' => 0,
            'with_turnover' => 0,
            'paying' => 0,
            'cap_reached' => 0,
            'commission_net' => 0,
            'commission_open' => 0,
            'commission_invoiced' => 0,
            'commission_paid' => 0,
            'commission_overdue' => 0,
        ];
    }

    /**
     * @return list<TopTenantStat>
     */
    private function topTenants(string $period): array
    {
        return TenantBillingPeriod::query()
            ->withoutGlobalScopes()
            ->where('period', $period)
            ->where('turnover_minor', '>', 0)
            ->with('tenant:id,name,slug,status')
            ->orderByDesc('turnover_minor')
            ->orderBy('tenant_id')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->filter(fn (TenantBillingPeriod $row): bool => $row->tenant !== null)
            ->map(fn (TenantBillingPeriod $row): TopTenantStat => new TopTenantStat(
                tenantId: $row->tenant->id,
                tenantName: $row->tenant->name,
                tenantSlug: $row->tenant->slug,
                tenantStatus: $row->tenant->status->value,
                turnoverMinor: $row->turnover_minor,
                commissionMinor: $row->commission_minor,
                correctionMinor: $row->correction_minor,
                netMinor: $this->billableNet($row),
                capReached: $row->cap_reached,
            ))
            ->values()
            ->all();
    }

    /**
     * The PHP twin of {@see self::NET_EXPRESSION} for a single row: what the
     * period is actually billed for. A voided period is billed for nothing — it
     * either had no invoice to begin with or the invoice was stornoed (§6.5).
     */
    private function billableNet(TenantBillingPeriod $row): int
    {
        if ($row->status === BillingPeriodStatus::Void) {
            return 0;
        }

        return max(0, $row->commission_minor + $row->correction_minor);
    }

    /**
     * Every currently overdue invoice framed as suspension risk (docs/10 §6.6):
     * ordered by due date so the closest suspension floats to the top. Not
     * scoped to a period — dunning risk is a live, cross-month concern.
     *
     * @return list<OverdueInvoiceStat>
     */
    private function overdueInvoices(): array
    {
        $now = Carbon::now();

        return CommissionInvoice::query()
            ->withoutGlobalScopes()
            ->where('status', CommissionInvoiceStatus::Overdue->value)
            ->whereNotNull('due_at')
            ->with('tenant:id,name,slug')
            ->orderBy('due_at')
            ->get()
            ->map(function (CommissionInvoice $invoice) use ($now): OverdueInvoiceStat {
                $suspendAt = $invoice->due_at->copy()->addDays(DunningSweep::GRACE_DAYS);
                $secondsToSuspend = $now->diffInSeconds($suspendAt, false);

                return new OverdueInvoiceStat(
                    invoiceId: $invoice->id,
                    tenantId: $invoice->tenant_id,
                    tenantName: $invoice->tenant?->name,
                    tenantSlug: $invoice->tenant?->slug,
                    period: $invoice->period,
                    totalGrossMinor: $invoice->total_gross_minor,
                    currency: $invoice->currency,
                    dueAt: $invoice->due_at,
                    suspendAt: $suspendAt,
                    daysUntilSuspension: $secondsToSuspend > 0 ? (int) ceil($secondsToSuspend / 86_400) : 0,
                );
            })
            ->all();
    }

    /**
     * Tenants that can currently operate (trial or active) — the funnel's top of
     * the funnel. Archived tenants are soft-deleted and excluded already.
     */
    private function operationalTenantCount(): int
    {
        return Tenant::query()
            ->whereIn('status', [TenantStatus::Trial->value, TenantStatus::Active->value])
            ->count();
    }
}
