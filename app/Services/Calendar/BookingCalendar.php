<?php

namespace App\Services\Calendar;

/**
 * The admin calendar's read model (SLO-44, docs/05 M7). Every time in here is
 * already expressed as *minutes from the tenant-local midnight of its own day*, so
 * the frontend positions a booking with plain arithmetic and cannot re-introduce a
 * timezone or DST error while doing it.
 */
final readonly class BookingCalendar
{
    /**
     * @param  'day'|'week'  $view
     * @param  string  $date  Anchor day (tenant-local Y-m-d).
     * @param  string  $rangeStart  First day shown (tenant-local Y-m-d).
     * @param  string  $rangeEnd  Last day shown (tenant-local Y-m-d).
     * @param  int  $windowStartMinute  Top of the grid, minutes from midnight.
     * @param  int  $windowEndMinute  Bottom of the grid, minutes from midnight.
     * @param  list<array{key: string, label: string, sublabel: string|null, date: string|null, resource_id: int|null}>  $columns
     * @param  list<array<string, mixed>>  $events  Placed bookings.
     * @param  list<array<string, mixed>>  $unscheduled  In-range bookings with no start time.
     */
    public function __construct(
        public string $view,
        public string $date,
        public string $rangeStart,
        public string $rangeEnd,
        public string $prevDate,
        public string $nextDate,
        public string $today,
        public string $timezone,
        public int $windowStartMinute,
        public int $windowEndMinute,
        public array $columns,
        public array $events,
        public array $unscheduled,
    ) {}
}
