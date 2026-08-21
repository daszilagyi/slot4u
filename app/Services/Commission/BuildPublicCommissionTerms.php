<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionSetting;
use Illuminate\Support\Carbon;

/**
 * Reads the commission terms the landing page advertises (SLO-50).
 *
 * @see PublicCommissionTerms for why this is read live rather than hardcoded.
 */
final class BuildPublicCommissionTerms
{
    /** The example is "three times the free threshold" so it moves with it. */
    private const int EXAMPLE_THRESHOLD_MULTIPLE = 3;

    /** Used only when the threshold is zero and the multiple would give 0 Ft. */
    private const int EXAMPLE_FALLBACK_TURNOVER_MINOR = 3_000_000;

    public function __construct(private readonly CommissionCalculator $calculator) {}

    /**
     * Null when the platform has published no commission settings at all. The
     * page then states the model in words and quotes no figures — inventing a
     * threshold nobody configured is exactly the drift this class exists to
     * prevent.
     */
    public function build(): ?PublicCommissionTerms
    {
        $setting = CommissionSetting::query()
            ->where('effective_from', '<=', Carbon::now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($setting === null) {
            return null;
        }

        $turnover = $setting->free_threshold_minor > 0
            ? $setting->free_threshold_minor * self::EXAMPLE_THRESHOLD_MULTIPLE
            : self::EXAMPLE_FALLBACK_TURNOVER_MINOR;

        // The example runs through the same calculator that produces the real
        // invoice, at the base rate (the example describes a tenant with no
        // rate-raising integration). If the marginal rule or the cap ever
        // changes, the number on the marketing page changes with it.
        $result = $this->calculator->calculate(
            [new CommissionItem($turnover, $setting->rate_bps)],
            $setting->free_threshold_minor,
            $setting->monthly_cap_minor,
        );

        return new PublicCommissionTerms(
            freeThresholdMinor: $setting->free_threshold_minor,
            rateBps: $setting->rate_bps,
            rateWithIntegrationBps: $setting->rate_with_integration_bps,
            monthlyCapMinor: $setting->monthly_cap_minor,
            currency: $setting->currency,
            exampleTurnoverMinor: $result->turnoverMinor,
            exampleBillableBaseMinor: $result->billableBaseMinor,
            exampleCommissionMinor: $result->commissionMinor,
        );
    }
}
