<?php

declare(strict_types=1);

namespace App\Actions\Rbac;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\TenantTeam;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Creates a tenant's own role (SLO-142). The role starts with no permissions:
 * the tenant grants them in the same editor afterwards, so a new role can never
 * be born carrying more than its author consciously ticked.
 *
 * The name is the tenant's own label — roles are unique per team
 * (team = tenant_id), so two tenants may both have a "Recepciós" and neither
 * sees the other's. Uniqueness and the reserved built-in names are the
 * FormRequest's job; this action owns the write and its audit entry.
 */
final class CreateTenantRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantTeam $team,
    ) {}

    public function __invoke(Tenant $tenant, string $name): RoleModel
    {
        $role = $this->team->run($tenant, function () use ($tenant, $name): RoleModel {
            // spatie's factory is typed against its Role contract; the concrete
            // model is what the rest of the app (and the policy) works with.
            /** @var RoleModel $role */
            $role = RoleModel::create([
                'name' => $name,
                'guard_name' => 'web',
                $this->team->key() => $tenant->getKey(),
            ]);

            $this->audit->record(
                action: AuditAction::RoleCreated,
                auditable: $role,
                newValues: ['role' => $name, 'permissions' => []],
                tenantId: $tenant->getKey(),
            );

            return $role;
        });

        $this->team->flush();

        return $role;
    }
}
