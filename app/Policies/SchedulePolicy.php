<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Http\Requests\Admin\ScheduleRequest;
use App\Models\Schedule;
use App\Models\User;
use App\Support\ScheduleVisibility;

/**
 * Weekly schedules are tenant master data managed by users with `schedule.manage`
 * (tenant-admin, manager and — with the "saját" scope — employee, per the docs/03
 * matrix). The per-record abilities additionally enforce that scope
 * ({@see ScheduleVisibility}): without `schedule.manage_all` an actor may only
 * touch the schedule of a staff record they are linked to. Room schedules have no
 * owner, so they need the wide code.
 *
 * The scope is applied on route binding too (Schedule::resolveRouteBinding), so a
 * colleague's band surfaces as a 404 before this policy ever runs — no intra-tenant
 * existence leak. This is the defence-in-depth copy for direct Gate calls.
 *
 * Cross-tenant access is impossible either way: the BelongsToTenant global scope
 * 404s another tenant's record on binding. Super-admins pass via Gate::before.
 */
class SchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    /**
     * Creating is checked against the submitted schedulable in the Form Request
     * ({@see ScheduleRequest}), which is where the target
     * is known; here only the coarse grant can be tested.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $user->can(Permission::ScheduleManage->value)
            && ScheduleVisibility::owns($user, $schedule);
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $user->can(Permission::ScheduleManage->value)
            && ScheduleVisibility::owns($user, $schedule);
    }
}
