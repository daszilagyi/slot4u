<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see which customers (docs/03 matrix, SLO-84). Tenant-admin and manager
 * see every customer of the tenant; an employee sees only "saját ügyfelei" — the
 * customers who have a booking with a staff record the employee is linked to
 * (staff.user_id === actor). Single source of truth for both the list query
 * ({@see apply}) and per-record checks ({@see owns}), so the two never drift.
 */
final class CustomerVisibility
{
    /** Roles that see the whole customer roster (no ownership restriction). */
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
