<?php

namespace App\Services\Feature;

use App\Enums\Feature;
use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Services\Plan\PlanLimitService;
use Illuminate\Support\Collection;

/**
 * Resolves whether a feature is enabled for a tenant (docs/03).
 *
 * Resolution order:
 *   1. `tenant_features` override — a superadmin per-tenant on/off switch; wins
 *      whenever a row exists (even when it disables a plan-default feature).
 *   2. `plan_features` default — otherwise the base plan decides.
 *
 * This is the single source of truth behind the Pennant feature definitions
 * (see FeatureServiceProvider) and the Inertia feature share.
 */
class FeatureResolver
{
    /**
     * Base-plan default feature codes, memoised for the lifetime of the instance
     * (request- or job-scoped via the container binding) so the plan lookup runs
     * once even when many features are resolved one by one through Pennant.
     *
     * @var Collection<int, string>|null
     */
    private ?Collection $planDefaults = null;

    /**
     * Whether the given feature is enabled for the tenant.
     */
    public function enabled(Tenant $tenant, Feature $feature): bool
    {
        $override = TenantFeature::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('feature_code', $feature->value)
            ->value('enabled');

        if ($override !== null) {
            return (bool) $override;
        }

        return $this->planDefaults()->contains($feature->value);
    }

    /**
     * The feature codes currently enabled for the tenant, for the frontend to
     * gate UI on. Batched into two queries regardless of feature count.
     *
     * @return list<string>
     */
    public function enabledCodes(Tenant $tenant): array
    {
        $overrides = [];

        foreach (TenantFeature::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->get() as $row) {
            $overrides[$row->feature_code->value] = $row->enabled;
        }

        $defaults = $this->planDefaults();

        return collect(Feature::cases())
            ->filter(fn (Feature $feature) => array_key_exists($feature->value, $overrides)
                ? $overrides[$feature->value]
                : $defaults->contains($feature->value))
            ->map(fn (Feature $feature) => $feature->value)
            ->values()
            ->all();
    }

    /**
     * Feature codes granted by default on the active base plan.
     *
     * @return Collection<int, string>
     */
    private function planDefaults(): Collection
    {
        return $this->planDefaults ??= PlanFeature::query()
            ->whereHas('plan', fn ($query) => $query
                ->where('code', PlanLimitService::BASE_PLAN_CODE)
                ->where('is_active', true))
            ->pluck('feature_code')
            ->map(fn (Feature $feature) => $feature->value);
    }
}
