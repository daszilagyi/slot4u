<?php

namespace App\Actions\Event;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an announced event (SLO-20): flips its status to `canceled`. This is
 * the path for an event that already has registrations — the registrant
 * notification + refund signal fire off the status change in M5/M3. "This and
 * following" cancels the later occurrences of the series too.
 */
class CancelEvent
{
    public function __invoke(Event $event, bool $applyToFollowing): Event
    {
        DB::transaction(function () use ($event, $applyToFollowing): void {
            if ($applyToFollowing && $event->series_id !== null) {
                Event::query()
                    ->where('series_id', $event->series_id)
                    ->where('starts_at', '>', $event->starts_at)
                    ->update(['status' => EventStatus::Canceled->value]);
            }

            // status is guarded (not fillable) — set it directly, not via update().
            $event->status = EventStatus::Canceled;
            $event->save();
        });

        return $event;
    }
}
