<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Service;
use App\Models\User;

/**
 * Services are tenant master data managed only by users with `service.manage`
 * (tenant-admin per the docs/03 matrix). Cross-tenant access is already
 * impossible: the BelongsToTenant global scope 404s another tenant's record on
 * binding, so these checks are permission-only. Super-admins pass via
 * Gate::before.
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }
}
