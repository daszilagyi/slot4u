<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantCommissionOverride;
use DateTimeInterface;
use RuntimeException;

/**
 * Resolves the effective commission parameters for a tenant at a given instant
 * (docs/10 §6.4): pick the platform {@see CommissionSetting} version in force at
 * `$asOf`, then overlay the tenant's {@see TenantCommissionOverride}, where a
 * NULL override field inherits the platform value (§5.2).
 *
 * IO-bound but tenant-context independent: the override is fetched by primary
 * key with global scopes removed, so the resolver works inside queue jobs and
 * superadmin/cross-tenant contexts (e.g. RecomputeTenantPeriod, J5) where no
 * tenant is bound. The returned DTO carries the `settings_id` for the ledger
 * rate snapshot.
 */
final class ResolveTenantCommissionSettings
{
    /**
     * @param  DateTimeInterface  $asOf  The period reference instant (UTC) the
     *                                   effective settings version is pinned to.
     */
    public function resolve(Tenant $tenant, DateTimeInterface $asOf): ResolvedCommissionSettings
    {
        $setting = CommissionSetting::query()
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($setting === null) {
            throw new RuntimeException(
                'No commission_settings effective at '.$asOf->format(DateTimeInterface::ATOM).'.',
            );
        }

        $override = TenantCommissionOverride::query()
            ->withoutGlobalScopes()
            ->whereKey($tenant->getKey())
            ->first();

        // Start from the effective platform settings; a present override fills in
        // only its non-null fields (NULL = inherit, §5.2). The cap stays nullable
        // (null platform cap = uncapped).
        $freeThresholdMinor = $setting->free_threshold_minor;
        $rateBps = $setting->rate_bps;
        $rateWithIntegrationBps = $setting->rate_with_integration_bps;
        $monthlyCapMinor = $setting->monthly_cap_minor;

        if ($override instanceof TenantCommissionOverride) {
            $freeThresholdMinor = $override->free_threshold_minor ?? $freeThresholdMinor;
            $rateBps = $override->rate_bps ?? $rateBps;
            $rateWithIntegrationBps = $override->rate_with_integration_bps ?? $rateWithIntegrationBps;
            $monthlyCapMinor = $override->monthly_cap_minor ?? $monthlyCapMinor;
        }

        return new ResolvedCommissionSettings(
            freeThresholdMinor: $freeThresholdMinor,
            rateBps: $rateBps,
            rateWithIntegrationBps: $rateWithIntegrationBps,
            monthlyCapMinor: $monthlyCapMinor,
            currency: $setting->currency,
            settingsId: $setting->id,
        );
    }
}
