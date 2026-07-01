<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Location;
use App\Models\User;

/**
 * Locations are tenant master data managed only by users with `location.manage`
 * (tenant-admin per the docs/03 matrix — managers/employees do not get it).
 * Cross-tenant access is already impossible: the BelongsToTenant global scope
 * 404s another tenant's record on binding, so these checks are permission-only.
 * Super-admins pass via the Gate::before hook.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can(Permission::LocationManage->value);
    }
}
