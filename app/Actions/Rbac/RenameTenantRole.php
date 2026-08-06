<?php

declare(strict_types=1);

namespace App\Actions\Rbac;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\TenantTeam;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Renames a tenant's own role (SLO-142).
 *
 * Only custom roles reach here — the policy refuses a built-in one, because the
 * seeded names are load-bearing: seeding, the members-area wall and the reset
 * button all identify a role by its name, so renaming `manager` would leave a
 * role that no longer matches anything the code knows about.
 *
 * The name is the role's identity in spatie's pivots, and grants are keyed by
 * role id, so a rename carries every assignment with it.
 */
final class RenameTenantRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantTeam $team,
    ) {}

    public function __invoke(Tenant $tenant, RoleModel $role, string $name): void
    {
        $this->team->run($tenant, function () use ($tenant, $role, $name): void {
            $before = $role->name;

            $role->name = $name;
            $role->save();

            $this->audit->record(
                action: AuditAction::RoleRenamed,
                auditable: $role,
                oldValues: ['role' => $before],
                newValues: ['role' => $name],
                tenantId: $tenant->getKey(),
            );
        });

        // The role name is part of spatie's cached permission map; without the
        // flush an authorization check could still resolve the old name.
        $this->team->flush();
    }
}
