<?php

namespace App\Actions\Event;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Updates an announced event (SLO-20). "This only" edits the single occurrence.
 * "This and following" additionally propagates the resource/capacity fields to
 * the later occurrences of the same series (never the per-occurrence times, which
 * stay distinct). Capacity propagation is guarded so no occurrence drops below
 * its own registrations.
 */
class UpdateEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Event $event, array $data, bool $applyToFollowing): Event
    {
        DB::transaction(function () use ($event, $data, $applyToFollowing): void {
            $event->update([
                'service_id' => $data['service_id'],
                'staff_id' => $data['staff_id'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'capacity' => $data['capacity'],
                'waitlist_enabled' => (bool) ($data['waitlist_enabled'] ?? false),
            ]);

            if (! $applyToFollowing || $event->series_id === null) {
                return;
            }

            // Propagate the non-temporal fields to later occurrences of the series.
            $following = Event::query()
                ->where('series_id', $event->series_id)
                ->where('starts_at', '>', $event->starts_at)
                ->whereKeyNot($event->getKey());

            (clone $following)->update([
                'staff_id' => $event->staff_id,
                'room_id' => $event->room_id,
                'waitlist_enabled' => $event->waitlist_enabled,
            ]);

            // Capacity only where it would not fall below that occurrence's bookings.
            (clone $following)
                ->where('booked_count', '<=', $event->capacity)
                ->update(['capacity' => $event->capacity]);
        });

        return $event;
    }
}
