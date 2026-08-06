<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see which customers (docs/03 matrix, SLO-84). A holder of
 * `customer.view_all` sees every customer of the tenant; anyone else with
 * `customer.view` sees only "saját ügyfelei" — the customers who have a booking
 * with a staff record the actor is linked to (staff.user_id === actor). Single
 * source of truth for both the list query ({@see apply}) and per-record checks
 * ({@see owns}), so the two never drift.
 */
final class CustomerVisibility
{
    /**
     * Whether the actor sees the whole customer roster (no ownership restriction).
     *
     * Until SLO-142 this asked for the tenant-admin or manager role by NAME.
     * That could not survive custom roles: a tenant-defined "senior receptionist"
     * would have been silently own-scoped with no way to widen it. The seeded
     * grant is unchanged — tenant-admin and manager carry `customer.view_all`,
     * employee does not — but the distinction is now a code the tenant can move.
     */
    public static function unrestricted(User $actor): bool
    {
        return $actor->can(Permission::CustomerViewAll->value);
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

    /** Whether the actor may see this specific customer. */
    public static function owns(User $actor, User $customer): bool
    {
        if (self::unrestricted($actor)) {
            return true;
        }

        $staffIds = self::actorStaffIds($actor);

        return $staffIds !== [] && $customer->bookings()->whereIn('staff_id', $staffIds)->exists();
    }

    /**
     * Restrict a customer query to what the actor may see. A no-op for the
     * unrestricted roles; an employee with no staff link sees nobody.
     *
     * @param  Builder<covariant \App\Models\User>  $query
     */
    public static function apply(Builder $query, User $actor): void
    {
        if (self::unrestricted($actor)) {
            return;
        }

        $staffIds = self::actorStaffIds($actor);

        // Empty staffIds → whereIn([]) is always false → the employee sees nobody.
        $query->whereHas('bookings', fn (Builder $q) => $q->whereIn('staff_id', $staffIds));
    }
}
