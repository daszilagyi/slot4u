<?php

namespace App\Services\Rbac;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Tenant;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the default tenant roles (docs/03 matrix) inside a tenant's team
 * (team = tenant_id). Self-sufficient and idempotent: ensures the global
 * permission codes exist before assigning them, so it is safe to call from a
 * Tenant `created` observer regardless of seeder order.
 */
class TenantRoleSeeder
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function seed(Tenant $tenant): void
    {
        $this->ensurePermissions();

        $previousTeamId = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenant->getKey());

        try {
            foreach (RoleEnum::tenantRoles() as $role) {
                $model = RoleModel::findOrCreate($role->value, 'web');
                $model->syncPermissions(
                    array_map(fn (Permission $permission) => $permission->value, $role->permissions()),
                );
            }
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeamId);
        }

        $this->registrar->forgetCachedPermissions();
    }

    private function ensurePermissions(): void
    {
        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }
    }
}
