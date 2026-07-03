<?php

namespace Database\Seeders;

use App\Enums\BookingMode;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
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
        $acme = $this->makeTenant('Acme Studio', 'acme', TenantStatus::Active, 'admin@acme.test');
        $this->makeTenant('Suspended Demo', 'suspended-demo', TenantStatus::Suspended, 'admin@suspended-demo.test');

        // A couple of staff members on the active tenant (SLO-17), as plain
        // calendar resources — the invitation flow is exercised from the UI.
        if (Staff::query()->where('tenant_id', $acme->id)->doesntExist()) {
            Staff::factory()->forTenant($acme)->create(['name' => 'Kis Anna', 'title' => 'Fodrász', 'color' => '#ec4899']);
            Staff::factory()->forTenant($acme)->create(['name' => 'Nagy Béla', 'title' => 'Masszőr', 'color' => '#3b82f6']);
        }

        // Demo services across a couple of booking modes (SLO-18).
        if (Service::query()->where('tenant_id', $acme->id)->doesntExist()) {
            $category = ServiceCategory::factory()->forTenant($acme)->create(['name' => 'Wellness']);
            Service::factory()->forTenant($acme)->create([
                'category_id' => $category->id,
                'name' => 'Svédmasszázs',
                'booking_mode' => BookingMode::DurationBased,
                'duration_minutes' => 60,
                'buffer_after_minutes' => 10,
                'price_minor' => 1200000,
                'requires_staff' => true,
            ]);
            Service::factory()->forTenant($acme)->eventBased()->create([
                'category_id' => $category->id,
                'name' => 'Csoportos jóga',
                'price_minor' => 350000,
            ]);
        }
    }

    private function makeTenant(string $name, string $slug, TenantStatus $status, string $email): Tenant
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

        return $tenant;
    }
}
