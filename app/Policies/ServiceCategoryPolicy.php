<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceCategory;
use App\Models\User;

/**
 * Service categories share the `service.manage` grant with services (docs/03).
 * Tenant isolation comes from the BelongsToTenant global scope (cross-tenant
 * binding 404s), so these are permission-only checks. Super-admins pass via
 * Gate::before.
 */
class ServiceCategoryPolicy
{
    public function create(User $user): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    public function delete(User $user, ServiceCategory $category): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }
}
