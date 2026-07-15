<?php

namespace App\Actions\Event;

use App\Actions\Booking\ChangeBookingStatus;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Services\Booking\WaitlistService;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an announced event (SLO-20): flips its status to `canceled`. This is
 * the path for an event that already has registrations — the registrant
 * bookings are canceled and each registrant is notified (SLO-111). "This and
 * following" cancels the later occurrences of the series too. The event's active
 * waitlist places are expired (SLO-25) — there is nothing left to offer.
 */
class CancelEvent
{
    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly ChangeBookingStatus $changeStatus,
    ) {}

    public function __invoke(Event $event, bool $applyToFollowing): Event
    {
        DB::transaction(function () use ($event, $applyToFollowing): void {
            $canceledIds = [(int) $event->id];

            if ($applyToFollowing && $event->series_id !== null) {
                $followingIds = Event::query()
                    ->where('series_id', $event->series_id)
                    ->where('starts_at', '>', $event->starts_at)
                    ->pluck('id');

                Event::query()
                    ->whereIn('id', $followingIds)
                    ->update(['status' => EventStatus::Canceled->value]);

                $canceledIds = array_merge($canceledIds, $followingIds->map(fn ($id) => (int) $id)->all());
            }

            // status is guarded (not fillable) — set it directly, not via update().
            $event->status = EventStatus::Canceled;
            $event->save();

            // A waiting/offered place on a canceled occurrence is moot → expire it.
            // Waitlisters never held a confirmed spot, so they get no separate
            // notice here (docs/04 §3 only requires notifying registrants).
            $this->waitlist->expireForEvents($canceledIds);

            // Cancel the registrants' bookings on the now-dead occurrences. The
            // events are already `canceled`, so the seat release + offerNext inside
            // ChangeBookingStatus can't promote a waiter onto a dead event
            // (EventStatus::Scheduled guard, SLO-25). Each cancellation fires
            // BookingCanceled → the registrant is emailed (SLO-109). Refund
            // signalling is gated on feature_online_payment and lands with the M6
            // payment flow (docs/04 §3).
            $this->cancelRegistrantBookings($canceledIds, (int) $event->tenant_id);
        });

        return $event;
    }

    /**
     * Cancel every still-cancelable booking on the given (canceled) events, in the
     * enclosing transaction, through the sanctioned state machine.
     *
     * @param  list<int>  $eventIds
     */
    private function cancelRegistrantBookings(array $eventIds, int $tenantId): void
    {
        $bookings = Booking::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('event_id', $eventIds)
            ->whereIn('status', BookingStatus::cancelableValues())
            ->get();

        foreach ($bookings as $booking) {
            ($this->changeStatus)(
                $booking,
                BookingStatus::Canceled,
                reason: __('app.admin.events.cancel_booking_reason'),
            );
        }
    }
}
