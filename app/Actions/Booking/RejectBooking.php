<?php

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\InvalidBookingTransitionException;
use App\Models\Booking;
use App\Models\User;

/**
 * Rejects a requested booking with a reason (docs/04 §5, SLO-26). The reason is a
 * business rule of manual_approval (not just a form nicety), so it is required at
 * this layer — a future non-web entry point (Phase-2 API, console) can't reject
 * silently. Delegates the transition + history + events + hold release to
 * {@see ChangeBookingStatus}, which stores the reason in `reject_reason`. An event
 * booking's seat is returned by the same path (SLO-25).
 */
class RejectBooking
{
    public function __construct(private readonly ChangeBookingStatus $changeStatus) {}

    /**
     * @throws InvalidBookingTransitionException when the booking is not requested
     */
    public function __invoke(Booking $booking, ?User $actor, string $reason): Booking
    {
        return ($this->changeStatus)($booking, BookingStatus::Rejected, $actor, $reason);
    }
}
