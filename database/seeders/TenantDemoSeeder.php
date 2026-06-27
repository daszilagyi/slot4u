<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\TenantRoleSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds two demo tenants for manual subdomain verification:
 * - acme.slot4u.test          (active)    → tenant home
 * - suspended-demo.slot4u.test (suspended) → suspended status page
 *
 * Each gets a tenant-admin user (password: "password").
 */
class TenantDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->makeTenant('Acme Studio', 'acme', TenantStatus::Active, 'admin@acme.test');
        $this->makeTenant('Suspended Demo', 'suspended-demo', TenantStatus::Suspended, 'admin@suspended-demo.test');
    }

    private function makeTenant(string $name, string $slug, TenantStatus $status, string $email): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'status' => $status],
        );

        // Model events are muted during seeding, so the Tenant observer does not
        // fire — seed the tenant's roles explicitly.
        app(TenantRoleSeeder::class)->seed($tenant);

        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'tenant_id' => $tenant->id,
                'name' => $name.' Admin',
                'password' => Hash::make('password'),
            ],
        );

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $admin->syncRoles([Role::TenantAdmin->value]);
        $registrar->setPermissionsTeamId(null);
    }
}
