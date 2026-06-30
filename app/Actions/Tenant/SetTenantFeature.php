<?php

namespace App\Actions\Tenant;

use App\Enums\AuditAction;
use App\Enums\Feature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Services\Audit\AuditLogger;

/**
 * Superadmin per-tenant feature override (SLO-77): upserts a `tenant_features`
 * row that the FeatureResolver reads on top of the plan default. Runs in admin
 * context (no bound tenant), so tenant_id is set explicitly and survives the
 * BelongsToTenant creating hook (which only stamps when a tenant is bound).
 * Audit-logged (SLO-78).
 */
class SetTenantFeature
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Tenant $tenant, Feature $feature, bool $enabled, ?int $overriddenBy = null): void
    {
        $override = TenantFeature::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('feature_code', $feature)
            ->first()
            ?? new TenantFeature(['feature_code' => $feature]);

        $oldEnabled = $override->exists ? (bool) $override->enabled : null;

        // tenant_id is intentionally not fillable (the BelongsToTenant trait
        // stamps it from the bound tenant) — set it explicitly here since the
        // admin panel runs without a bound tenant.
        $override->tenant_id = $tenant->getKey();
        $override->enabled = $enabled;
        $override->overridden_by = $overriddenBy;
        $override->save();

        $this->audit->record(
            action: AuditAction::TenantFeatureToggled,
            auditable: $tenant,
            oldValues: ['feature' => $feature->value, 'enabled' => $oldEnabled],
            newValues: ['feature' => $feature->value, 'enabled' => $enabled],
        );
    }
}
