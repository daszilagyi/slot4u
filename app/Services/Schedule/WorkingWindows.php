<?php

namespace App\Services\Schedule;

use App\Enums\ScheduleExceptionType;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Services\Booking\AvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * When is a bookable resource open (docs/02 §Elérhetőség)? Weekly schedule bands
 * for the weekday, within their validity window, plus "extra" openings, minus
 * "off" closures.
 *
 * Extracted from {@see AvailabilityService} (SLO-137) so
 * that capacity — the denominator of the utilisation report — is computed from
 * exactly the same rule that decides which slots are bookable. Two copies of
 * "when is this room open" would eventually disagree, and the report would then
 * quietly contradict the booking engine.
 *
 * Windows are local Carbon instants in the tenant timezone, so the arithmetic on
 * them is real elapsed time: a band that spans a DST jump is genuinely one hour
 * shorter, which is what a capacity figure has to say.
 */
final class WorkingWindows
{
    /**
     * Open local-time windows for one resource on one date.
     *
     * When a location is requested, only bands scoped to it (or to no location)
     * count — a multi-location staff's other-location bands drop out (docs/02 §54,
     * SLO-51).
     *
     * @param  Collection<int, Schedule>  $schedules  bands already loaded for the day
     * @param  Collection<int, ScheduleException>  $exceptions
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public static function forDay(
        string $type,
        int $resourceId,
        Carbon $day,
        string $timezone,
        Collection $schedules,
        Collection $exceptions,
        ?int $locationId = null,
    ): array {
        $isoDay = $day->isoWeekday();
        $dateString = $day->toDateString();

        $windows = $schedules
            ->where('schedulable_type', $type)
            ->where('schedulable_id', $resourceId)
            ->where('day_of_week', $isoDay)
            ->filter(fn (Schedule $s) => self::validOn($s, $dateString) && self::matchesLocation($s, $locationId))
            ->map(fn (Schedule $s) => [
                self::at($day, $s->start_time, $timezone),
                self::at($day, $s->end_time, $timezone),
            ])
            ->values()
            ->all();

        $dayExceptions = $exceptions
            ->where('schedulable_type', $type)
            ->where('schedulable_id', $resourceId)
            ->filter(fn (ScheduleException $e) => $e->date->toDateString() === $dateString);

        // Extra openings add windows.
        foreach ($dayExceptions->where('type', ScheduleExceptionType::Extra) as $extra) {
            if ($extra->start_time !== null && $extra->end_time !== null) {
                $windows[] = [self::at($day, $extra->start_time, $timezone), self::at($day, $extra->end_time, $timezone)];
            }
        }

        // Off exceptions cut windows (whole day when timeless).
        foreach ($dayExceptions->where('type', ScheduleExceptionType::Off) as $off) {
            $offStart = $off->start_time !== null ? self::at($day, $off->start_time, $timezone) : $day->copy();
            $offEnd = $off->end_time !== null ? self::at($day, $off->end_time, $timezone) : $day->copy()->addDay();
            $windows = self::subtract($windows, $offStart, $offEnd);
        }

        return $windows;
    }

    /**
     * Cut [cutStart, cutEnd] out of each window, splitting where it lands inside.
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public static function subtract(array $windows, Carbon $cutStart, Carbon $cutEnd): array
    {
        $result = [];

        foreach ($windows as [$windowStart, $windowEnd]) {
            // No overlap: keep as is.
            if ($cutEnd->lte($windowStart) || $cutStart->gte($windowEnd)) {
                $result[] = [$windowStart, $windowEnd];

                continue;
            }
            // Left remainder.
            if ($cutStart->gt($windowStart)) {
                $result[] = [$windowStart, $cutStart->copy()];
            }
            // Right remainder.
            if ($cutEnd->lt($windowEnd)) {
                $result[] = [$cutEnd->copy(), $windowEnd];
            }
        }

        return $result;
    }

    /**
     * Total open minutes, clipped to [$from, $to] when a bound is given. Overlapping
     * bands are NOT merged — that is deliberate: the loader hands one resource's own
     * bands, and the schedule UI already prevents a resource from being open twice at
     * the same moment.
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     */
    public static function minutes(array $windows, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $total = 0;

        foreach ($windows as [$start, $end]) {
            $clippedStart = $from !== null && $start->lt($from) ? $from : $start;
            $clippedEnd = $to !== null && $end->gt($to) ? $to : $end;

            if ($clippedEnd->gt($clippedStart)) {
                $total += (int) $clippedStart->diffInMinutes($clippedEnd);
            }
        }

        return $total;
    }

    /**
     * A local Carbon at the given H:i(:s) wall-clock time on the date.
     */
    public static function at(Carbon $day, string $time, string $timezone): Carbon
    {
        return Carbon::parse($day->toDateString().' '.substr($time, 0, 5), $timezone);
    }

    /**
     * A band applies to the requested location when it is location-agnostic (null)
     * or scoped to exactly that location. No requested location = all bands.
     */
    private static function matchesLocation(Schedule $schedule, ?int $locationId): bool
    {
        return $locationId === null
            || $schedule->location_id === null
            || $schedule->location_id === $locationId;
    }

    private static function validOn(Schedule $schedule, string $dateString): bool
    {
        $from = $schedule->valid_from?->toDateString();
        $until = $schedule->valid_until?->toDateString();

        return ($from === null || $dateString >= $from)
            && ($until === null || $dateString <= $until);
    }
}
