<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Booking;
use App\Models\User;

/**
 * Bookings are managed by users with the booking permissions (docs/03 matrix:
 * tenant-admin, manager, and — with the "saját" scope arriving in M3/M4 — employee
 * and customer). Cross-tenant access is impossible: the BelongsToTenant global
 * scope 404s another tenant's record on binding, so these are permission checks
 * only. Super-admins pass via the Gate::before hook.
 */
class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BookingView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BookingCreate->value);
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingEdit->value);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingCancel->value);
    }

    /**
     * Approve / reject / propose an alternative for an approval-pending booking
     * (docs/04 §5, SLO-26).
     */
    public function approve(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingApprove->value);
    }
}
