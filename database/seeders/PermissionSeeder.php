<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Services\Rbac\TenantRoleSeeder;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the platform-global permission codes (docs/03). Per-tenant roles are
 * seeded by {@see TenantRoleSeeder} via the Tenant created
 * observer. The formal global super-admin role lands with the superadmin panel
 * (SLO-14); for now a tenant-less user is a super-admin by invariant.
 *
 * Idempotent — safe to re-run.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
