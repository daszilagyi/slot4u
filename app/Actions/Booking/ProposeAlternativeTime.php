<?php

namespace App\Actions\Booking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Offers the customer a different time for a requested booking (docs/04 §5, SLO-26):
 * the original request is rejected and a fresh requested booking is created at the
 * proposed slot, soft-held and awaiting a decision. Both happen in one transaction —
 * if the proposed slot is unavailable the whole thing rolls back and the original
 * request survives. The customer-facing accept/decline surface arrives with the
 * members area (M4, SLO-33); until then an admin approves the new request.
 *
 * Only time-slotted modes (duration_based / resource_rental) can be re-timed: an
 * event is a fixed occurrence and no_time_slot has no time to move.
 */
class ProposeAlternativeTime
{
    public function __construct(
        private readonly ChangeBookingStatus $changeStatus,
        private readonly CreateBooking $createBooking,
    ) {}

    /**
     * @param  array<string, mixed>  $slot  staff_id/room_id/starts_at/ends_at (UTC)
     *
     * @throws ValidationException when the booking's mode has no movable slot
     */
    public function __invoke(Booking $booking, array $slot, ?User $actor = null, ?string $reason = null): Booking
    {
        $service = $booking->service;
        if ($service === null) {
            throw new RuntimeException('Cannot propose an alternative for a booking without a service.');
        }

        if (! $booking->booking_mode->usesTimeSlot()) {
            throw ValidationException::withMessages([
                'booking' => __('app.admin.approvals.error.slot_mode_only'),
            ]);
        }

        return DB::transaction(function () use ($booking, $service, $slot, $actor, $reason): Booking {
            ($this->changeStatus)(
                $booking,
                BookingStatus::Rejected,
                $actor,
                $reason ?? __('app.admin.approvals.alternative_offered'),
            );

            // The proposed slot re-enters as a requested booking: source=online keeps
            // the approval gate so it starts requested + soft-held (CreateBooking).
            $data = array_merge($slot, [
                'customer_id' => $booking->customer_id,
                'party_size' => $booking->party_size,
                'notes' => $booking->notes,
                'source' => BookingSource::Online->value,
            ]);

            return ($this->createBooking)($service, $data, $actor);
        });
    }
}
