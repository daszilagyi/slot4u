<?php

namespace App\Actions\Booking;

use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reschedules a booking = cancel the old one + create the new one in a single
 * transaction (docs/04 §2). If the new slot is unavailable, {@see CreateBooking}
 * throws and the whole transaction rolls back — the original booking survives
 * untouched.
 *
 * MVP scope (docs/04 §2): only time-slot modes (duration_based / resource_rental)
 * can be rescheduled — no_time_slot has no slot to move, event_based means changing
 * event registration, and quote_request is bound to an accepted quote. A non-slot
 * mode is rejected up front (before the original is touched).
 *
 * The `$online` flag is threaded into the inner {@see CancelBooking}: an online
 * (customer/members-area, SLO-97) reschedule enforces the tenant cancellation
 * deadline — otherwise a within-deadline booking could be moved to sidestep the
 * cancellation window. The admin path leaves it false (deadline-free).
 */
class RescheduleBooking
{
    public function __construct(
        private readonly CancelBooking $cancelBooking,
        private readonly CreateBooking $createBooking,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the new booking's attributes
     * @param  bool  $online  enforce the cancellation deadline (customer reschedule)
     *
     * @throws ValidationException when the mode cannot be rescheduled, or (online) the
     *                             cancellation deadline has passed
     */
    public function __invoke(Booking $original, Service $service, array $data, ?User $actor = null, bool $online = false): Booking
    {
        // Guard the mode here (not just in the UI) so a direct API call can't move a
        // no_time_slot/event/quote booking into an invalid state (docs/04 §2).
        if (! $original->booking_mode->usesTimeSlot()) {
            throw ValidationException::withMessages([
                'starts_at' => __('app.admin.bookings.error.reschedule_mode'),
            ]);
        }

        return DB::transaction(function () use ($original, $service, $data, $actor, $online): Booking {
            // online: true enforces the cancellation deadline in CancelBooking; a
            // within-deadline attempt throws (on the `cancel` key) and rolls the whole
            // transaction back, so the original booking survives untouched (SLO-97).
            //
            // rescheduled: true keeps the customer from being told their booking was
            // canceled — the replacement carries the pointer back to this booking, and
            // sends a single "your booking was moved" mail instead (SLO-109).
            ($this->cancelBooking)($original, $actor, __('app.booking.rescheduled'), $online, rescheduled: true);

            return ($this->createBooking)($service, $data, $actor, rescheduledFrom: $original);
        });
    }
}
