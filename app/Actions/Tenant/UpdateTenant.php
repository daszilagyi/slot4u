<?php

namespace App\Actions\Tenant;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;

/**
 * Superadmin update of a tenant's base fields (name/slug/timezone/locale).
 * Kept in an Action (like the status transitions) so the audit log (SLO-78) is
 * wired uniformly and the logic stays entry-point agnostic.
 */
class UpdateTenant
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(Tenant $tenant, array $attributes): void
    {
        $old = array_intersect_key($tenant->only(array_keys($attributes)), $attributes);

        $tenant->update($attributes);

        $this->audit->record(
            action: AuditAction::TenantUpdated,
            auditable: $tenant,
            oldValues: $old,
            newValues: $attributes,
        );
    }
}
