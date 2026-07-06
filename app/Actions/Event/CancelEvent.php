<?php

namespace App\Actions\Event;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\Booking\WaitlistService;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an announced event (SLO-20): flips its status to `canceled`. This is
 * the path for an event that already has registrations — the registrant
 * notification + refund signal fire off the status change in M5/M3. "This and
 * following" cancels the later occurrences of the series too. The event's active
 * waitlist places are expired (SLO-25) — there is nothing left to offer.
 */
class CancelEvent
{
    public function __construct(private readonly WaitlistService $waitlist) {}

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
            $this->waitlist->expireForEvents($canceledIds);
        });

        return $event;
    }
}
