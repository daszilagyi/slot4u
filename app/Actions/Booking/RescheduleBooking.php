<?php

namespace App\Actions\Booking;

use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reschedules a booking = cancel the old one + create the new one in a single
 * transaction (docs/04 §2). If the new slot is unavailable, {@see CreateBooking}
 * throws and the whole transaction rolls back — the original booking survives
 * untouched.
 *
 * MVP scope: cancellation here is treated as admin-initiated (no cancellation-
 * deadline check). event_based capacity release on cancel — decrementing
 * events.booked_count — arrives with the event registration/waitlist work (SLO-25);
 * until then this is only wired for time-slot modes.
 */
class RescheduleBooking
{
    public function __construct(
        private readonly CancelBooking $cancelBooking,
        private readonly CreateBooking $createBooking,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the new booking's attributes
     */
    public function __invoke(Booking $original, Service $service, array $data, ?User $actor = null): Booking
    {
        return DB::transaction(function () use ($original, $service, $data, $actor): Booking {
            ($this->cancelBooking)($original, $actor, __('app.booking.rescheduled'));

            return ($this->createBooking)($service, $data, $actor);
        });
    }
}
