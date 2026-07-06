<?php

namespace App\Actions\Booking;

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Events\BookingCanceled;
use App\Events\BookingStatusChanged;
use App\Exceptions\InvalidBookingTransitionException;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Services\Booking\WaitlistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moves a booking to a new status through the shared state machine (docs/04, SLO-23):
 * rejects an illegal transition, records the move in booking_status_history with the
 * actor, applies the status side effects (approval/cancellation metadata) and fires
 * the domain events M5 listeners hook into. This is the ONLY sanctioned way to
 * change a booking's status.
 *
 * When an event booking leaves the occupying set (canceled/rejected/no_show) it
 * frees its seats: booked_count is atomically returned and the freed place is
 * offered to the next waitlisted customer (docs/04 §3, SLO-25).
 */
class ChangeBookingStatus
{
    public function __construct(private readonly WaitlistService $waitlist) {}

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
            // Serialise concurrent status writers — the soft-hold expiry job now
            // races admin approve/reject on the same row (SLO-26). Re-read under a
            // lock and bail if the status already moved, so a stale write can't
            // clobber another actor's transition.
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();
            if ($locked !== null && $locked->status !== $from) {
                throw new InvalidBookingTransitionException($locked->status, $to);
            }

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

            if ($to === BookingStatus::Rejected) {
                $booking->reject_reason = $reason;
            }

            // Leaving the requested state releases its soft hold (docs/04 §5, SLO-26).
            if ($from === BookingStatus::Requested) {
                $booking->hold_expires_at = null;
            }

            $booking->save();

            $booking->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor?->getKey(),
            ]);

            // An event booking that stops occupying its seat returns capacity and
            // hands the freed place to the next waiter (docs/04 §3, SLO-25).
            if ($this->eventSeatFreed($booking, $from, $to)) {
                $this->releaseEventSeat($booking);
                $this->waitlist->offerNext((int) $booking->event_id, (int) $booking->tenant_id);
            }

            BookingStatusChanged::dispatch($booking, $from, $to, $actor);

            if ($to === BookingStatus::Canceled) {
                BookingCanceled::dispatch($booking, $actor);
            }

            return $booking;
        });
    }

    /**
     * Whether this transition frees an event seat: an event booking moving from an
     * occupying status to a non-occupying one (canceled/rejected/no_show).
     */
    private function eventSeatFreed(Booking $booking, BookingStatus $from, BookingStatus $to): bool
    {
        return $booking->booking_mode === BookingMode::EventBased
            && $booking->event_id !== null
            && $from->occupiesSlot()
            && ! $to->occupiesSlot();
    }

    /**
     * Atomically return the booking's seats to the event. The `>=` guard floors
     * booked_count at zero (defence against a double release).
     */
    private function releaseEventSeat(Booking $booking): void
    {
        $partySize = (int) $booking->party_size;

        Event::query()
            ->where('tenant_id', $booking->tenant_id)
            ->whereKey($booking->event_id)
            ->where('booked_count', '>=', $partySize)
            ->update(['booked_count' => DB::raw('booked_count - '.$partySize)]);
    }
}
