<?php

namespace App\Policies;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\RescheduleBooking;
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

    /**
     * A customer viewing their OWN booking in the members area (SLO-94). This is
     * the ownership axis, distinct from the staff permission abilities above — a
     * customer holds no booking permission codes (SLO-86), so their "own data"
     * access (docs/03) is purely ownership. The members controller maps a denial
     * to 404, not 403, so a booking that isn't theirs is indistinguishable from a
     * non-existent one (hidden existence, like a cross-tenant id).
     */
    public function viewOwn(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->getKey();
    }

    /**
     * A customer cancelling their OWN booking online (SLO-94). The cancellation
     * deadline itself is enforced in {@see CancelBooking} (online: true); this
     * ability is only the ownership gate.
     */
    public function cancelOwn(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->getKey();
    }

    /**
     * A customer rescheduling their OWN booking online (members area, SLO-97).
     * Same ownership rule as cancelOwn; the cancellation deadline is enforced in
     * {@see RescheduleBooking} (online: true), not here.
     */
    public function rescheduleOwn(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->getKey();
    }
}
