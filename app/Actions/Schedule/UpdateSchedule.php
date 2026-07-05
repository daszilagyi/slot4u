<?php

namespace App\Actions\Schedule;

use App\Models\Schedule;

/**
 * Updates a weekly schedule band (SLO-19). The schedulable is fixed at creation
 * and never reassigned, so only the fillable timing/validity fields change.
 */
class UpdateSchedule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Schedule $schedule, array $data): Schedule
    {
        $schedule->update($data);

        return $schedule;
    }
}
