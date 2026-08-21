<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a development database: the required platform data, plus demo tenants
     * and a placeholder user to log in with.
     *
     * ⚠️ NOT for production — see {@see ProductionSeeder}, which is what the
     * deploy runs.
     */
    public function run(): void
    {
        // Everything a real environment needs (SLO-166) — one list, so the
        // development seed and the deploy cannot drift apart.
        $this->call(ProductionSeeder::class);

        // ⚠️ Everything below is DEVELOPMENT ONLY and must never reach a real
        // host. That is why the deploy calls ProductionSeeder and not this one.
        $this->call(TenantDemoSeeder::class);

        // Tenant-less user (acts as a placeholder superadmin until SLO-14).
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
