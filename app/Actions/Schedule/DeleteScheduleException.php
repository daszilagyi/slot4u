<?php

namespace App\Actions\Schedule;

use App\Models\ScheduleException;

/**
 * Deletes a schedule exception (SLO-19).
 */
class DeleteScheduleException
{
    public function __invoke(ScheduleException $exception): void
    {
        $exception->delete();
    }
}
