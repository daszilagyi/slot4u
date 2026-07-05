<?php

namespace App\Actions\Event;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hard-deletes an announced event (SLO-20). An event with registrations must be
 * cancelled (registrants notified), not deleted — so deletion is blocked once
 * booked_count > 0. "This and following" also removes the later, still-empty
 * occurrences of the series.
 */
class DeleteEvent
{
    public function __invoke(Event $event, bool $applyToFollowing): void
    {
        if ($event->booked_count > 0) {
            throw ValidationException::withMessages([
                'delete' => __('app.admin.events.error.delete_has_bookings'),
            ]);
        }

        DB::transaction(function () use ($event, $applyToFollowing): void {
            if ($applyToFollowing && $event->series_id !== null) {
                Event::query()
                    ->where('series_id', $event->series_id)
                    ->where('starts_at', '>', $event->starts_at)
                    ->where('booked_count', 0)
                    ->delete();
            }

            $event->delete();
        });
    }
}
