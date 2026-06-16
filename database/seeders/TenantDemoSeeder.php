<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'tenant_id' => $tenant->id,
                'name' => $name.' Admin',
                'password' => Hash::make('password'),
            ],
        );
    }
}
