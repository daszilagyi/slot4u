<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see which bookings (docs/03 matrix, SLO-85). Tenant-admin and manager
 * see every booking of the tenant; an employee sees only "saját foglalásai" — the
 * bookings assigned to a staff record they are linked to (staff.user_id === actor).
 * Bookings carry staff_id on the row, so the scope is a direct whereIn (no
 * whereHas needed, unlike {@see CustomerVisibility}). Single source of truth for
 * both the list query ({@see apply}) and per-record checks ({@see owns}), so the
 * two never drift.
 */
final class BookingVisibility
{
    /** Roles that see every booking of the tenant (no ownership restriction). */
    public static function unrestricted(User $actor): bool
    {
        return $actor->hasAnyRole([Role::TenantAdmin->value, Role::Manager->value]);
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

    /** Whether the actor may see this specific booking. */
    public static function owns(User $actor, Booking $booking): bool
    {
        if (self::unrestricted($actor)) {
            return true;
        }

        return $booking->staff_id !== null
            && in_array((int) $booking->staff_id, self::actorStaffIds($actor), true);
    }

    /**
     * Restrict a booking query to what the actor may see. A no-op for the
     * unrestricted roles; an employee with no staff link sees nothing.
     *
     * @param  Builder<Booking>  $query
     */
    public static function apply(Builder $query, User $actor): void
    {
        if (self::unrestricted($actor)) {
            return;
        }

        // Empty staffIds → whereIn([]) is always false → the employee sees nothing.
        $query->whereIn('staff_id', self::actorStaffIds($actor));
    }
}
