<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionSetting;

/**
 * The commission model as the public landing page states it (SLO-50).
 *
 * Deliberately built from the live platform {@see CommissionSetting}
 * rather than written into the copy: a pricing page that disagrees with the
 * invoice is worse than no pricing page, and the two drift the moment somebody
 * publishes a new settings version in the superadmin panel.
 *
 * No tenant is involved — this is the platform default a visitor would get on
 * sign-up, so tenant overrides (docs/10 §5.2) are intentionally NOT applied.
 */
final readonly class PublicCommissionTerms
{
    public function __construct(
        /** F — monthly free turnover threshold (minor units). */
        public int $freeThresholdMinor,
        /** Base rate (bps) with no rate-raising integration. */
        public int $rateBps,
        /** Raised rate (bps) with payment or invoicing integration (§2.4). */
        public int $rateWithIntegrationBps,
        /** K — monthly commission cap (minor units); null = uncapped. */
        public ?int $monthlyCapMinor,
        public string $currency,
        /** The worked example's turnover, derived from the threshold. */
        public int $exampleTurnoverMinor,
        /** The part of it commission is actually charged on. */
        public int $exampleBillableBaseMinor,
        /** What that comes to — computed by the real {@see CommissionCalculator}. */
        public int $exampleCommissionMinor,
    ) {}

    /**
     * @return array{
     *     free_threshold_minor: int,
     *     rate_bps: int,
     *     rate_with_integration_bps: int,
     *     monthly_cap_minor: int|null,
     *     currency: string,
     *     example_turnover_minor: int,
     *     example_billable_base_minor: int,
     *     example_commission_minor: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'free_threshold_minor' => $this->freeThresholdMinor,
            'rate_bps' => $this->rateBps,
            'rate_with_integration_bps' => $this->rateWithIntegrationBps,
            'monthly_cap_minor' => $this->monthlyCapMinor,
            'currency' => $this->currency,
            'example_turnover_minor' => $this->exampleTurnoverMinor,
            'example_billable_base_minor' => $this->exampleBillableBaseMinor,
            'example_commission_minor' => $this->exampleCommissionMinor,
        ];
    }
}
