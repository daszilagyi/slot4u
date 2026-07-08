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
 * mode is rejected up front (before the original is touched); cancellation here is
 * admin-initiated (no cancellation-deadline check).
 */
class RescheduleBooking
{
    public function __construct(
        private readonly CancelBooking $cancelBooking,
        private readonly CreateBooking $createBooking,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the new booking's attributes
     *
     * @throws ValidationException when the booking's mode cannot be rescheduled
     */
    public function __invoke(Booking $original, Service $service, array $data, ?User $actor = null): Booking
    {
        // Guard the mode here (not just in the UI) so a direct API call can't move a
        // no_time_slot/event/quote booking into an invalid state (docs/04 §2).
        if (! $original->booking_mode->usesTimeSlot()) {
            throw ValidationException::withMessages([
                'starts_at' => __('app.admin.bookings.error.reschedule_mode'),
            ]);
        }

        return DB::transaction(function () use ($original, $service, $data, $actor): Booking {
            ($this->cancelBooking)($original, $actor, __('app.booking.rescheduled'));

            return ($this->createBooking)($service, $data, $actor);
        });
    }
}
