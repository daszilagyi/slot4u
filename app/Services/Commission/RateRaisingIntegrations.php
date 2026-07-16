<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Enums\Feature;
use App\Models\Tenant;
use App\Services\Feature\FeatureResolver;

/**
 * The rate-raising integrations (online payment, invoicing — docs/10 §2.4) a
 * tenant currently has active. Enabling one is free, but bumps the commission
 * rate from `rate_bps` to `rate_with_integration_bps` for every booking that
 * becomes billable while it is on.
 *
 * Resolved per explicit tenant (no ambient Pennant scope), so it is correct both
 * off the request cycle (the J5 ledger listener) and on the tenant billing
 * dashboard, which uses the active codes to explain *why* the rate is what it is.
 */
final class RateRaisingIntegrations
{
    public function __construct(private readonly FeatureResolver $features) {}

    public function active(Tenant $tenant): bool
    {
        return $this->activeCodes($tenant) !== [];
    }

    /**
     * @return list<string> The active rate-raising feature codes, in enum order.
     */
    public function activeCodes(Tenant $tenant): array
    {
        $codes = [];

        foreach (Feature::cases() as $feature) {
            if ($feature->raisesCommissionRate() && $this->features->enabled($tenant, $feature)) {
                $codes[] = $feature->value;
            }
        }

        return $codes;
    }
}
