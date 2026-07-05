<?php

namespace App\Actions\Schedule;

use App\Models\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Copies every band a resource has on the source weekday onto each target
 * weekday (SLO-19, "másolás napok közt"). Each target day's existing bands are
 * cleared first, so the copy is idempotent and the result never self-overlaps.
 */
class CopyScheduleDay
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): void
    {
        $type = (string) $data['schedulable_type'];
        $id = (int) $data['schedulable_id'];
        $sourceDay = (int) $data['source_day'];
        /** @var list<int> $targetDays */
        $targetDays = array_map('intval', $data['target_days']);

        $source = Schedule::query()
            ->where('schedulable_type', $type)
            ->where('schedulable_id', $id)
            ->where('day_of_week', $sourceDay)
            ->get();

        DB::transaction(function () use ($type, $id, $sourceDay, $targetDays, $source): void {
            foreach ($targetDays as $day) {
                if ($day === $sourceDay) {
                    continue;
                }

                Schedule::query()
                    ->where('schedulable_type', $type)
                    ->where('schedulable_id', $id)
                    ->where('day_of_week', $day)
                    ->delete();

                foreach ($source as $band) {
                    $copy = new Schedule;
                    $copy->schedulable_type = $type;
                    $copy->schedulable_id = $id;
                    $copy->location_id = $band->location_id;
                    $copy->day_of_week = $day;
                    $copy->start_time = $band->start_time;
                    $copy->end_time = $band->end_time;
                    $copy->valid_from = $band->valid_from;
                    $copy->valid_until = $band->valid_until;
                    $copy->save();
                }
            }
        });
    }
}
