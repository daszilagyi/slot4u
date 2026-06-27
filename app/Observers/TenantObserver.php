<?php

namespace App\Observers;

use App\Models\Tenant;
use App\Services\Rbac\TenantRoleSeeder;

class TenantObserver
{
    public function __construct(private readonly TenantRoleSeeder $roleSeeder) {}

    /**
     * Seed the default tenant roles whenever a tenant is created at runtime.
     * Seeder-created demo tenants run with model events muted, so those seed
     * roles explicitly (see TenantDemoSeeder).
     */
    public function created(Tenant $tenant): void
    {
        $this->roleSeeder->seed($tenant);
    }
}
