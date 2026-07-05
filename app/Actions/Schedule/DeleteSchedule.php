<?php

namespace App\Actions\Schedule;

use App\Models\Schedule;

/**
 * Deletes a weekly schedule band (SLO-19). Removing availability never cascades
 * to bookings — future-booking conflicts are surfaced as a warning list, not an
 * automatic deletion (docs/04 edge case; see FutureScheduleConflicts).
 */
class DeleteSchedule
{
    public function __invoke(Schedule $schedule): void
    {
        $schedule->delete();
    }
}
