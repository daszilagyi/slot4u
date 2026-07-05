<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Event;
use App\Models\User;

/**
 * Announced events are operational scheduling artifacts (meghirdetett alkalmak),
 * managed by users with `schedule.manage` — tenant-admin and manager per the
 * docs/03 matrix, the same gate as the weekly schedule. Cross-tenant access is
 * impossible: the BelongsToTenant global scope 404s another tenant's record on
 * binding. Super-admins pass via the Gate::before hook.
 */
class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function update(User $user, Event $event): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->can(Permission::ScheduleManage->value);
    }
}
