<?php

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Events\BookingCanceled;
use App\Events\BookingStatusChanged;
use App\Exceptions\InvalidBookingTransitionException;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moves a booking to a new status through the shared state machine (docs/04, SLO-23):
 * rejects an illegal transition, records the move in booking_status_history with the
 * actor, applies the status side effects (approval/cancellation metadata) and fires
 * the domain events M5 listeners hook into. This is the ONLY sanctioned way to
 * change a booking's status.
 */
class ChangeBookingStatus
{
    /**
     * @throws InvalidBookingTransitionException
     */
    public function __invoke(Booking $booking, BookingStatus $to, ?User $actor = null, ?string $reason = null): Booking
    {
        $from = $booking->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidBookingTransitionException($from, $to);
        }

        $actor ??= Auth::user();

        return DB::transaction(function () use ($booking, $from, $to, $actor, $reason): Booking {
            // status + the transition metadata are guarded (not fillable).
            $booking->status = $to;

            if ($to === BookingStatus::Approved) {
                $booking->approved_by = $actor?->getKey();
                $booking->approved_at = Carbon::now();
            }

            if ($to === BookingStatus::Canceled) {
                $booking->canceled_at = Carbon::now();
                $booking->cancel_reason = $reason;
            }

            $booking->save();

            $booking->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor?->getKey(),
            ]);

            BookingStatusChanged::dispatch($booking, $from, $to, $actor);

            if ($to === BookingStatus::Canceled) {
                BookingCanceled::dispatch($booking, $actor);
            }

            return $booking;
        });
    }
}
