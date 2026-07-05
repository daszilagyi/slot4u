<?php

namespace App\Actions\Schedule;

use App\Models\ScheduleException;

/**
 * Creates a schedule exception (SLO-19): time off or an extra opening on a date.
 * tenant_id is stamped by BelongsToTenant; the schedulable is set explicitly.
 */
class CreateScheduleException
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): ScheduleException
    {
        $exception = new ScheduleException;
        $exception->fill($data);
        $exception->schedulable_type = $data['schedulable_type'];
        $exception->schedulable_id = (int) $data['schedulable_id'];
        $exception->save();

        return $exception;
    }
}
