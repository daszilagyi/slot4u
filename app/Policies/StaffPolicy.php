<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Staff;
use App\Models\User;

/**
 * Staff are tenant master data managed by users with `staff.manage`
 * (tenant-admin per the docs/03 matrix). The one exception is self-service:
 * an employee linked to a staff record may edit their OWN profile even without
 * staff.manage (docs/03 "employee a saját profilját szerkesztheti").
 *
 * Cross-tenant access is already impossible: the BelongsToTenant global scope
 * 404s another tenant's record on binding, so these are permission checks only.
 * Super-admins pass via the Gate::before hook.
 */
class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::StaffManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::StaffManage->value);
    }

    public function update(User $user, Staff $staff): bool
    {
        return $user->can(Permission::StaffManage->value)
            || $this->ownsProfile($user, $staff);
    }

    public function delete(User $user, Staff $staff): bool
    {
        return $user->can(Permission::StaffManage->value);
    }

    /**
     * Whether this staff record is the acting user's own linked profile.
     */
    private function ownsProfile(User $user, Staff $staff): bool
    {
        return $staff->user_id !== null && $staff->user_id === $user->getKey();
    }
}
