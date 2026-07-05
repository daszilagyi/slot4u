<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Schedule;
use App\Models\User;

/**
 * Weekly schedules are tenant master data managed by users with `schedule.manage`
 * (tenant-admin and manager per the docs/03 matrix). The employee "saját" scope
 * — an employee editing only their OWN linked staff's schedule — ships with the
 * "saját naptár" self-service view in M3 (docs/03 §Dolgozók, deferred alongside
 * the booking engine); until then the permission gates the whole feature.
 *
 * Cross-tenant access is already impossible: the BelongsToTenant global scope
 * 404s another tenant's record on binding, so these are permission checks only.
 * Super-admins pass via the Gate::before hook.
 */
class SchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }
}
