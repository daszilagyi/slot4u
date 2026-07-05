<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ScheduleException;
use App\Models\User;

/**
 * Schedule exceptions (leave, holiday, extra opening) follow the same access
 * rules as the weekly schedule they override: managed by users with
 * `schedule.manage` (docs/03). See {@see SchedulePolicy} for the employee "saját"
 * scope deferral. Cross-tenant binding 404s via the BelongsToTenant scope.
 */
class ScheduleExceptionPolicy
{
    public function create(User $user): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function delete(User $user, ScheduleException $exception): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }
}
