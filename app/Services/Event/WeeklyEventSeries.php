<?php

declare(strict_types=1);

namespace App\Services\Event;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The occurrence list behind a weekly event series (SLO-20 / SLO-82).
 *
 * Its own class because two callers have to agree on it exactly: the action that
 * creates the occurrences and the request that validates them. When the validator
 * only knew about the submitted occurrence, the other 259 were written without a
 * conflict check — a staff member could be double-booked on every later week of a
 * series, silently (SLO-82).
 *
 * Stepping happens in the tenant's wall clock, not on the UTC instant: a calendar
 * `addWeeks` keeps 18:00 at 18:00 across a DST changeover, while adding 168 hours
 * would drift it by one (docs/01 §7).
 */
final class WeeklyEventSeries
{
    /** Hard cap on generated occurrences, guarding against a runaway until-date. */
    public const MAX_OCCURRENCES = 260; // ~5 years of weekly events

    /**
     * The UTC start/end instants of every occurrence, the submitted one first.
     *
     * @param  CarbonInterface  $startUtc  the submitted occurrence's start
     * @param  CarbonInterface  $endUtc  the submitted occurrence's end
     * @param  CarbonInterface  $until  last day the series may reach (tenant-local)
     * @return list<array{starts_at: Carbon, ends_at: Carbon}>
     */
    public static function occurrences(
        CarbonInterface $startUtc,
        CarbonInterface $endUtc,
        CarbonInterface $until,
        string $timezone,
    ): array {
        $startLocal = Carbon::instance($startUtc->toDateTime())->setTimezone($timezone);
        $endLocal = Carbon::instance($endUtc->toDateTime())->setTimezone($timezone);

        $occurrences = [];

        for ($week = 0; $week < self::MAX_OCCURRENCES; $week++) {
            $starts = $startLocal->copy()->addWeeks($week);

            if ($starts->gt($until)) {
                break;
            }

            $occurrences[] = [
                'starts_at' => $starts->utc(),
                'ends_at' => $endLocal->copy()->addWeeks($week)->utc(),
            ];
        }

        return $occurrences;
    }
}
