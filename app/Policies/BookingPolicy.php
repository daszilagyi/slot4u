<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Booking;
use App\Models\User;
use App\Support\BookingVisibility;

/**
 * Bookings are managed by users with the booking permissions (docs/03 matrix:
 * tenant-admin, manager, and — with the "saját" scope, SLO-85 — employee). Cross-
 * tenant access is impossible: the BelongsToTenant global scope 404s another
 * tenant's record on binding. The per-record abilities additionally enforce the
 * employee ownership scope ({@see BookingVisibility}): an employee may only touch
 * bookings of a staff record they are linked to; the admin controller surfaces a
 * hidden booking as a 404 (not 403) before this runs. Super-admins pass via the
 * Gate::before hook.
 */
class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BookingView->value);
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingView->value)
            && BookingVisibility::owns($user, $booking);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BookingCreate->value);
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingEdit->value)
            && BookingVisibility::owns($user, $booking);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingCancel->value)
            && BookingVisibility::owns($user, $booking);
    }

    /**
     * Approve / reject / propose an alternative for an approval-pending booking
     * (docs/04 §5, SLO-26).
     */
    public function approve(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingApprove->value)
            && BookingVisibility::owns($user, $booking);
    }
}
