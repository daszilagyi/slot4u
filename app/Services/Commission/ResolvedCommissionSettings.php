<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionSetting;
use App\Models\TenantCommissionOverride;

/**
 * Immutable, resolved commission parameters for a tenant at a point in time
 * (docs/10 §2.1, §6.4): the effective platform {@see CommissionSetting}
 * merged with the tenant's nullable {@see TenantCommissionOverride}.
 *
 * All amounts are integer minor units; rates are integer basis points (never float).
 */
final readonly class ResolvedCommissionSettings
{
    public function __construct(
        /** F — monthly free turnover threshold (minor units). */
        public int $freeThresholdMinor,
        /** Base rate (bps) when no rate-raising integration is active. */
        public int $rateBps,
        /** Raised rate (bps) when a rate-raising integration is active (§2.4). */
        public int $rateWithIntegrationBps,
        /** K — monthly commission cap (minor units); null = uncapped. */
        public ?int $monthlyCapMinor,
        public string $currency,
        /** Id of the platform commission_settings version used (ledger snapshot). */
        public int $settingsId,
    ) {}

    /**
     * The rate (bps) that applies to a booking, given whether a rate-raising
     * integration was active at the moment it became billable (§2.4).
     */
    public function rateFor(bool $integrationActive): int
    {
        return $integrationActive ? $this->rateWithIntegrationBps : $this->rateBps;
    }
}
