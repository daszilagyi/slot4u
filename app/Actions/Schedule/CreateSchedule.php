<?php

namespace App\Actions\Schedule;

use App\Models\Schedule;

/**
 * Creates a weekly schedule band (SLO-19). tenant_id is stamped by
 * BelongsToTenant; the polymorphic schedulable is set explicitly (never
 * mass-assigned) from the already tenant-validated request data.
 */
class CreateSchedule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Schedule
    {
        $schedule = new Schedule;
        $schedule->fill($data);
        $schedule->schedulable_type = $data['schedulable_type'];
        $schedule->schedulable_id = (int) $data['schedulable_id'];
        $schedule->save();

        return $schedule;
    }
}
