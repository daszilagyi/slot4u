<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $this->call(BasePlanSeeder::class);

        $this->call(CommissionSettingSeeder::class);

        // Placeholder platform terms + privacy notice (SLO-161). Without a
        // version in force, sign-up asks for no acceptance at all — art. 7(1)
        // would be undemonstrable from the first tenant onwards.
        $this->call(LegalDocumentSeeder::class);

        $this->call(TenantDemoSeeder::class);

        // Tenant-less user (acts as a placeholder superadmin until SLO-14).
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
