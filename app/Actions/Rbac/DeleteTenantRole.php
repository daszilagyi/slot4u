<?php

declare(strict_types=1);

namespace App\Actions\Rbac;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\TenantTeam;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Deletes a tenant's own role (SLO-142).
 *
 * Refuses while any user still holds the role. Deleting it out from under them
 * would silently strip their permissions — and, for a user whose only role this
 * is, lock them out of the panel entirely on their next request, with nothing in
 * the UI that said so. The tenant must reassign those users first, which is the
 * same decision made explicitly.
 *
 * Whether the role may be deleted at all (custom only, never the actor's own) is
 * the policy's call; this action owns the write, the occupancy check that the
 * policy cannot express, and the audit entry.
 */
final class DeleteTenantRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantTeam $team,
    ) {}

    /** @return bool False when the role still has holders and was kept. */
    public function __invoke(Tenant $tenant, RoleModel $role): bool
    {
        $deleted = $this->team->run($tenant, function () use ($tenant, $role): bool {
            if ($this->holderCount($role) > 0) {
                return false;
            }

            $before = [
                'role' => $role->name,
                'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
            ];

            // The spatie pivots (role_has_permissions, model_has_roles) cascade
            // on the foreign key, so the row is enough.
            $role->delete();

            $this->audit->record(
                action: AuditAction::RoleDeleted,
                oldValues: $before,
                tenantId: $tenant->getKey(),
            );

            return true;
        });

        if ($deleted) {
            $this->team->flush();
        }

        return $deleted;
    }

    /** How many users of this tenant currently hold the role. */
    public function holderCount(RoleModel $role): int
    {
        return $role->users()->count();
    }
}
