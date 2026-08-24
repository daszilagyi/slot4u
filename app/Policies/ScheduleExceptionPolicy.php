<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Http\Requests\Admin\ScheduleExceptionRequest;
use App\Models\ScheduleException;
use App\Models\User;
use App\Support\ScheduleVisibility;

/**
 * Schedule exceptions (leave, holiday, extra opening) follow the same access
 * rules as the weekly schedule they override: `schedule.manage` for the grant,
 * plus the employee "saját" scope from {@see SchedulePolicy} — an actor without
 * `schedule.manage_all` may only take time off from their own staff record.
 *
 * The scope is applied on route binding too (ScheduleException::resolveRouteBinding),
 * so a colleague's exception 404s before this runs. Cross-tenant binding 404s via
 * the BelongsToTenant scope.
 */
class ScheduleExceptionPolicy
{
    /**
     * The submitted schedulable is scope-checked in the Form Request
     * ({@see ScheduleExceptionRequest}); here only the
     * coarse grant can be tested.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function delete(User $user, ScheduleException $exception): bool
    {
        return $user->can(Permission::ScheduleManage->value)
            && ScheduleVisibility::owns($user, $exception);
    }
}
