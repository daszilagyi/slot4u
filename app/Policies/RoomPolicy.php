<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Room;
use App\Models\User;

/**
 * Rooms live under their location and are governed by the same `location.manage`
 * permission (docs/03). Tenant isolation is enforced by the global scope; these
 * checks are permission-only. Super-admins pass via the Gate::before hook.
 */
class RoomPolicy
{
    public function create(User $user): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function update(User $user, Room $room): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function delete(User $user, Room $room): bool
    {
        return $user->can(Permission::LocationManage->value);
    }
}
