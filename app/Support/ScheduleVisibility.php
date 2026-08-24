<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose working hours may an actor see and change (docs/03 matrix, SLO-177).
 *
 * A holder of `schedule.manage_all` manages every resource of the tenant; anyone
 * else with `schedule.manage` is held to the matrix's employee "saját" cell — the
 * staff records they are linked to (staff.user_id === actor). Schedules and
 * exceptions carry the owner on the row (`schedulable_type`/`schedulable_id`), so
 * the scope is a direct morph filter, like {@see BookingVisibility}.
 *
 * ⚠️ A ROOM has no ownership axis. Nobody is "linked" to a room the way a staff
 * record links to a user, so a restricted actor sees no room schedule at all —
 * not even a room they happen to work in. Widening that would need a genuine
 * room-ownership concept, which the data model does not have; until it does, the
 * safe reading of "saját" is "the resource that IS me".
 *
 * Single source of truth for the list queries ({@see apply}), the per-record
 * checks ({@see owns}) and the write-side scope check ({@see ownsSchedulable}),
 * so the three never drift.
 */
final class ScheduleVisibility
{
    /** The morph alias of a staff record — the only schedulable with an owner. */
    private const STAFF = 'staff';

    /**
     * Whether the actor manages every resource's schedule (no ownership limit).
     *
     * A permission code rather than a role name, for the SLO-142 reason: a
     * tenant-defined "shift lead" role could never satisfy a name test and would
     * be silently own-scoped forever, with nothing in the role editor to change.
     */
    public static function unrestricted(User $actor): bool
    {
        return $actor->can(Permission::ScheduleManageAll->value);
    }

    /**
     * Staff records the actor is linked to (their own resources).
     *
     * @return list<int>
     */
    public static function actorStaffIds(User $actor): array
    {
        return Staff::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('user_id', $actor->getKey())
            ->pluck('id')->all();
    }

    /** Whether the actor may manage this specific band or exception. */
    public static function owns(User $actor, Schedule|ScheduleException $record): bool
    {
        return self::ownsSchedulable($actor, $record->schedulable_type, (int) $record->schedulable_id);
    }

    /**
     * Whether a schedulable reference — as submitted on a create/copy form, where
     * there is no persisted record to check yet — falls inside the actor's scope.
     */
    public static function ownsSchedulable(User $actor, ?string $type, ?int $id): bool
    {
        if (self::unrestricted($actor)) {
            return true;
        }

        if ($type !== self::STAFF || $id === null) {
            return false;
        }

        return in_array($id, self::actorStaffIds($actor), true);
    }

    /**
     * Restrict a schedule/exception query to what the actor may manage. A no-op
     * for an unrestricted actor; an employee with no staff link sees nothing.
     *
     * @param  Builder<Schedule>|Builder<ScheduleException>  $query
     */
    public static function apply(Builder $query, User $actor): void
    {
        if (self::unrestricted($actor)) {
            return;
        }

        // Empty staffIds → whereIn([]) is always false → the actor sees nothing.
        $query->where('schedulable_type', self::STAFF)
            ->whereIn('schedulable_id', self::actorStaffIds($actor));
    }
}
