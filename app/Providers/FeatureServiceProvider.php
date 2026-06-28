<?php

namespace App\Providers;

use App\Enums\Feature as FeatureEnum;
use App\Models\Tenant;
use App\Services\Feature\FeatureResolver;
use App\Tenancy\TenantManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * Wires Laravel Pennant to the tenant feature model (docs/03).
 *
 * Pennant's default scope is the current tenant, so `Feature::active('...')` and
 * the EnsureFeatureEnabled middleware are tenant-scoped without passing a scope
 * explicitly. Each feature flag delegates to FeatureResolver (tenant_features
 * override → plan_features default). Outside tenant context (console, admin
 * panel, queue) the scope is null and every flag resolves to off.
 */
class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Feature::resolveScopeUsing(fn () => app(TenantManager::class)->current());

        $resolver = $this->app->make(FeatureResolver::class);

        foreach (FeatureEnum::cases() as $feature) {
            Feature::define(
                $feature->value,
                fn (?Tenant $tenant): bool => $tenant instanceof Tenant && $resolver->enabled($tenant, $feature),
            );
        }
    }
}
