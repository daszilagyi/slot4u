<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use RuntimeException;

/**
 * Builds a period's commission standing for the tenant transparency dashboard
 * (docs/10 §9): how much turnover the tenant has produced, how much of the free
 * allowance is left, what has accrued, how far the cap is, and which rate
 * applies — plus why.
 *
 * Reads the aggregate rather than the ledger: `tenant_billing_periods` is the
 * cache the recompute keeps correct (§5.4), and it is already frozen for a closed
 * period. The commission settings are pinned to the same reference instant the
 * recompute used, so a past period displays the threshold and cap that actually
 * priced it, not today's.
 */
final class BuildTenantBillingOverview
{
    /** Shown only until a pricing model exists; the settings carry the real one. */
    private const FALLBACK_CURRENCY = 'HUF';

    public function __construct(
        private readonly ResolveTenantCommissionSettings $resolveSettings,
        private readonly RateRaisingIntegrations $integrations,
        private readonly BillingPeriodClock $clock,
    ) {}

    /**
     * @param  TenantBillingPeriod|null  $aggregate  The period's row, or null when
     *                                               the tenant has produced no
     *                                               billable booking in it yet.
     */
    public function __invoke(Tenant $tenant, string $period, ?TenantBillingPeriod $aggregate): TenantBillingOverview
    {
        // No aggregate yet = no billable booking in the period, which reads the
        // same as an all-zero one.
        $turnover = 0;
        $billableBase = 0;
        $commission = 0;
        $capReached = false;

        if ($aggregate instanceof TenantBillingPeriod) {
            $turnover = $aggregate->turnover_minor;
            $billableBase = $aggregate->billable_base_minor;
            $commission = $aggregate->commission_minor;
            $capReached = $aggregate->cap_reached;
        }

        try {
            $settings = $this->resolveSettings->resolve(
                $tenant,
                $this->clock->referenceInstant($period, $tenant->timezone),
            );
        } catch (RuntimeException) {
            // No pricing model configured yet (docs/10 §6.4) — the ledger skips
            // such bookings entirely, so report the standing without the figures
            // we cannot know rather than implying a zero threshold.
            return new TenantBillingOverview(
                period: $period,
                currency: self::FALLBACK_CURRENCY,
                configured: false,
                turnoverMinor: $turnover,
                billableBaseMinor: $billableBase,
                commissionMinor: $commission,
                freeThresholdMinor: null,
                freeRemainingMinor: null,
                monthlyCapMinor: null,
                capRemainingMinor: null,
                capReached: $capReached,
                thresholdReached: false,
                rateBps: null,
                raisedRateBps: null,
                integrationCodes: [],
                recomputedAt: $aggregate?->recomputed_at,
            );
        }

        $cap = $settings->monthlyCapMinor;
        $integrationCodes = $this->integrations->activeCodes($tenant);

        return new TenantBillingOverview(
            period: $period,
            currency: $settings->currency,
            configured: true,
            turnoverMinor: $turnover,
            billableBaseMinor: $billableBase,
            commissionMinor: $commission,
            freeThresholdMinor: $settings->freeThresholdMinor,
            freeRemainingMinor: max(0, $settings->freeThresholdMinor - $turnover),
            monthlyCapMinor: $cap,
            capRemainingMinor: $cap === null ? null : max(0, $cap - $commission),
            capReached: $capReached,
            thresholdReached: $turnover > $settings->freeThresholdMinor,
            // The rate a booking becoming billable *right now* would freeze
            // (§2.4). Past items keep their own snapshot — shown per row.
            rateBps: $settings->rateFor($integrationCodes !== []),
            raisedRateBps: $settings->rateWithIntegrationBps,
            integrationCodes: $integrationCodes,
            recomputedAt: $aggregate?->recomputed_at,
        );
    }
}
