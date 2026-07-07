<?php

namespace App\Actions\Booking;

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Fulfils a no_time_slot booking — the admin closure step for manual /
 * downloadable fulfilment (docs/04 §1, SLO-27): the service has been delivered,
 * so the booking moves confirmed → completed through the sanctioned
 * {@see ChangeBookingStatus}. Digital fulfilment auto-completes at creation and
 * needs no manual step.
 *
 * Scoped to no_time_slot on purpose: completing time-slotted / event bookings
 * (marking attendance) is part of the admin booking management UI (SLO-28).
 */
class CompleteBooking
{
    public function __construct(private readonly ChangeBookingStatus $changeStatus) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Booking $booking, ?User $actor = null): Booking
    {
        if ($booking->booking_mode !== BookingMode::NoTimeSlot) {
            throw ValidationException::withMessages([
                'booking' => __('app.booking.error.complete_mode_only'),
            ]);
        }

        return ($this->changeStatus)($booking, BookingStatus::Completed, $actor);
    }
}
