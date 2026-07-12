<?php

namespace App\Services\Booking;

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\ScheduleExceptionType;
use App\Models\Booking;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Settings\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The heart of the booking engine (SLO-22, docs/04 §2 & §4): computes the free
 * bookable slots for a duration_based or resource_rental service on a given date
 * or range —  schedule bands − exceptions − existing bookings − buffers, stepped
 * on the tenant's slot grid.
 *
 * All computation happens in the tenant timezone (so the wall-clock grid and DST
 * are natural) and every returned Slot carries UTC instants (docs/01 §7). Each day
 * bulk-loads its schedules/exceptions/bookings in one whereIn each (no per-resource
 * N+1); a range loops day-by-day, so a longer range issues a fixed few queries per
 * day rather than one for the whole span. Every query is anchored to the service's
 * tenant explicitly, independent of the ambient TenantScope (defense-in-depth for
 * non-request callers — queue jobs, the Phase-2 API).
 */
class AvailabilityService
{
    /**
     * Free slots for a service on a single calendar date (tenant-local).
     *
     * @return list<Slot>
     */
    public function slotsForDay(Service $service, Carbon $date, ?int $staffId = null, ?int $roomId = null, ?int $locationId = null): array
    {
        if (! in_array($service->booking_mode, [BookingMode::DurationBased, BookingMode::ResourceRental], true)) {
            return [];
        }

        $timezone = $service->tenant->timezone;
        $settings = TenantSettings::fromArray($service->tenant->settings);
        $interval = $settings->slotIntervalMinutes;
        $duration = $this->effectiveDuration($service, $settings);

        if ($duration <= 0) {
            return [];
        }

        $day = $date->copy()->timezone($timezone)->startOfDay();
        $tenantId = (int) $service->tenant_id;

        // The resource whose schedule drives the grid: rooms for a rental, staff
        // for a duration-based service ("anyone" = union across assigned staff).
        // Requested ids are validated against the service's own resources so a
        // forged staff/room id from another tenant can never be scheduled.
        [$type, $resourceIds] = $this->primaryResources($service, $staffId, $roomId);
        if ($resourceIds === []) {
            return [];
        }

        // A pinned room must belong to the service (duration_based + room).
        if ($type === 'staff' && $roomId !== null && ! $service->rooms->contains('id', $roomId)) {
            return [];
        }

        $schedules = $this->loadSchedules($tenantId, $type, $resourceIds, $day);
        $exceptions = $this->loadExceptions($tenantId, $type, $resourceIds, $day);
        $bookings = $this->loadBookings($tenantId, $type, $resourceIds, $day);

        // For a duration-based service that also pins a room, the room must be free.
        $roomWindows = null;
        if ($type === 'staff' && $roomId !== null) {
            $roomWindows = $this->freeWindows('room', $roomId, $day, $timezone,
                $this->loadSchedules($tenantId, 'room', [$roomId], $day),
                $this->loadExceptions($tenantId, 'room', [$roomId], $day));
        }

        $slots = [];
        $seenStarts = [];

        foreach ($resourceIds as $resourceId) {
            $windows = $this->freeWindows($type, $resourceId, $day, $timezone, $schedules, $exceptions, $locationId);
            $resourceBookings = $bookings[$resourceId] ?? collect();

            foreach ($this->gridStarts($windows, $duration, $interval) as $startLocal) {
                $endLocal = $startLocal->copy()->addMinutes($duration);
                $startUtc = $startLocal->copy()->utc();
                $endUtc = $endLocal->copy()->utc();

                if ($this->clashesWithBooking($startUtc, $endUtc, $resourceBookings, $service)) {
                    continue;
                }

                if ($roomWindows !== null && ! $this->fitsWindows($startLocal, $endLocal, $roomWindows)) {
                    continue;
                }

                // "Anyone" (no explicit staff): one slot per start time is enough.
                $key = $startUtc->timestamp;
                if ($staffId === null && $type === 'staff' && isset($seenStarts[$key])) {
                    continue;
                }
                $seenStarts[$key] = true;

                $slots[] = new Slot(
                    start: $startUtc,
                    end: $endUtc,
                    staffId: $type === 'staff' ? $resourceId : null,
                    roomId: $type === 'room' ? $resourceId : $roomId,
                );
            }
        }

        usort($slots, fn (Slot $a, Slot $b) => $a->start <=> $b->start);

        return $slots;
    }

    /**
     * Free slots across an inclusive date range (tenant-local days).
     *
     * @return list<Slot>
     */
    public function slotsForRange(Service $service, Carbon $from, Carbon $to, ?int $staffId = null, ?int $roomId = null, ?int $locationId = null): array
    {
        $timezone = $service->tenant->timezone;
        $cursor = $from->copy()->timezone($timezone)->startOfDay();
        $last = $to->copy()->timezone($timezone)->startOfDay();

        $slots = [];
        while ($cursor->lte($last)) {
            array_push($slots, ...$this->slotsForDay($service, $cursor->copy(), $staffId, $roomId, $locationId));
            $cursor->addDay();
        }

        return $slots;
    }

    /**
     * The bookable slot for a caller-chosen free-range rental duration (docs/04 §4),
     * or null if the requested [start, start+duration] range is not fully free:
     * wrong mode / fixed duration, a duration outside the min/max bounds, an off-grid
     * start, an overrun past the room's opening, or a clash with a booking. Mirrors
     * slotsForDay's rules for one caller-chosen length instead of the min-length grid.
     */
    public function matchRentalSlot(Service $service, Carbon $start, int $duration, ?int $roomId): ?Slot
    {
        if ($service->booking_mode !== BookingMode::ResourceRental || $service->duration_minutes !== null) {
            return null;
        }

        if ($roomId === null || ! $service->rooms->contains('id', $roomId)) {
            return null;
        }

        if (! $this->isDurationAllowed($service, $duration)) {
            return null;
        }

        $timezone = $service->tenant->timezone;
        $settings = TenantSettings::fromArray($service->tenant->settings);
        $interval = $settings->slotIntervalMinutes;
        $minDuration = $this->effectiveDuration($service, $settings);

        $day = $start->copy()->timezone($timezone)->startOfDay();
        $tenantId = (int) $service->tenant_id;

        $schedules = $this->loadSchedules($tenantId, 'room', [$roomId], $day);
        $exceptions = $this->loadExceptions($tenantId, 'room', [$roomId], $day);
        $windows = $this->freeWindows('room', $roomId, $day, $timezone, $schedules, $exceptions);

        // The start MUST be a legit min-length grid start: since $duration >=
        // $minDuration, any valid longer-duration start is also a valid min-length
        // grid start, so this rejects off-grid crafted starts.
        $startLocal = $start->copy()->timezone($timezone);
        $onGrid = false;
        foreach ($this->gridStarts($windows, $minDuration, $interval) as $gridStart) {
            if ($gridStart->equalTo($startLocal)) {
                $onGrid = true;
                break;
            }
        }
        if (! $onGrid) {
            return null;
        }

        $endLocal = $startLocal->copy()->addMinutes($duration);
        if (! $this->fitsWindows($startLocal, $endLocal, $windows)) {
            return null;
        }

        $startUtc = $startLocal->copy()->utc();
        $endUtc = $endLocal->copy()->utc();

        $bookings = $this->loadBookings($tenantId, 'room', [$roomId], $day)[$roomId] ?? collect();
        if ($this->clashesWithBooking($startUtc, $endUtc, $bookings, $service)) {
            return null;
        }

        return new Slot(start: $startUtc, end: $endUtc, staffId: null, roomId: $roomId);
    }

    /**
     * Whether a requested rental duration is within the service's min/max bounds
     * (docs/04 §4, resource_rental). A fixed-duration service ignores the request.
     */
    public function isDurationAllowed(Service $service, int $minutes): bool
    {
        if ($minutes <= 0) {
            return false;
        }

        if ($service->duration_minutes !== null) {
            return $minutes === $service->duration_minutes;
        }

        $min = $service->settings['min_duration_minutes'] ?? null;
        $max = $service->settings['max_duration_minutes'] ?? null;

        return ($min === null || $minutes >= (int) $min)
            && ($max === null || $minutes <= (int) $max);
    }

    /**
     * The slot length to grid the day on: the fixed duration, or (for a free-range
     * rental) the minimum allowed duration so at least min-length slots surface.
     */
    private function effectiveDuration(Service $service, TenantSettings $settings): int
    {
        if ($service->duration_minutes !== null) {
            return $service->duration_minutes;
        }

        $min = $service->settings['min_duration_minutes'] ?? null;

        return $min !== null ? (int) $min : $settings->slotIntervalMinutes;
    }

    /**
     * @return array{0: string, 1: list<int>} [schedulable type, resource ids]
     */
    private function primaryResources(Service $service, ?int $staffId, ?int $roomId): array
    {
        if ($service->booking_mode === BookingMode::ResourceRental) {
            // A requested room must be one of the service's rooms; else no slots.
            $ids = $roomId !== null
                ? ($service->rooms->contains('id', $roomId) ? [$roomId] : [])
                : $service->rooms->pluck('id')->all();

            return ['room', array_values(array_map('intval', $ids))];
        }

        $ids = $staffId !== null
            ? ($service->staff->contains('id', $staffId) ? [$staffId] : [])
            : $service->staff->pluck('id')->all();

        return ['staff', array_values(array_map('intval', $ids))];
    }

    /**
     * Free local-time windows for one resource on the date: schedule bands for the
     * weekday within their validity, minus whole/partial "off" exceptions, plus
     * "extra" opening exceptions.
     *
     * When a location is requested, only bands scoped to it (or to no location)
     * count — a multi-location staff's other-location bands drop out (docs/02 §54,
     * SLO-51).
     *
     * @param  Collection<int, Schedule>  $schedules  all loaded bands, keyed by nothing
     * @param  Collection<int, ScheduleException>  $exceptions
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function freeWindows(string $type, int $resourceId, Carbon $day, string $timezone, Collection $schedules, Collection $exceptions, ?int $locationId = null): array
    {
        $isoDay = $day->isoWeekday();
        $dateString = $day->toDateString();

        $windows = $schedules
            ->where('schedulable_type', $type)
            ->where('schedulable_id', $resourceId)
            ->where('day_of_week', $isoDay)
            ->filter(fn (Schedule $s) => $this->validOn($s, $dateString) && $this->matchesLocation($s, $locationId))
            ->map(fn (Schedule $s) => [
                $this->at($day, $s->start_time, $timezone),
                $this->at($day, $s->end_time, $timezone),
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
                $windows[] = [$this->at($day, $extra->start_time, $timezone), $this->at($day, $extra->end_time, $timezone)];
            }
        }

        // Off exceptions cut windows (whole day when timeless).
        foreach ($dayExceptions->where('type', ScheduleExceptionType::Off) as $off) {
            $offStart = $off->start_time !== null ? $this->at($day, $off->start_time, $timezone) : $day->copy();
            $offEnd = $off->end_time !== null ? $this->at($day, $off->end_time, $timezone) : $day->copy()->addDay();
            $windows = $this->subtractInterval($windows, $offStart, $offEnd);
        }

        return $windows;
    }

    /**
     * A band applies to the requested location when it is location-agnostic (null)
     * or scoped to exactly that location. No requested location = all bands.
     */
    private function matchesLocation(Schedule $schedule, ?int $locationId): bool
    {
        return $locationId === null
            || $schedule->location_id === null
            || $schedule->location_id === $locationId;
    }

    private function validOn(Schedule $schedule, string $dateString): bool
    {
        $from = $schedule->valid_from?->toDateString();
        $until = $schedule->valid_until?->toDateString();

        return ($from === null || $dateString >= $from)
            && ($until === null || $dateString <= $until);
    }

    /**
     * A local Carbon at the given H:i(:s) wall-clock time on the date.
     */
    private function at(Carbon $day, string $time, string $timezone): Carbon
    {
        return Carbon::parse($day->toDateString().' '.substr($time, 0, 5), $timezone);
    }

    /**
     * Grid slot start times inside the free windows: stepped by the interval, the
     * full duration must fit within the window (buffers overrun band edges freely,
     * docs/04 AC).
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @return list<Carbon>
     */
    private function gridStarts(array $windows, int $duration, int $interval): array
    {
        $starts = [];

        foreach ($windows as [$windowStart, $windowEnd]) {
            $cursor = $windowStart->copy();
            while ($cursor->copy()->addMinutes($duration)->lte($windowEnd)) {
                $starts[] = $cursor->copy();
                $cursor->addMinutes($interval);
            }
        }

        return $starts;
    }

    /**
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     */
    private function fitsWindows(Carbon $start, Carbon $end, array $windows): bool
    {
        foreach ($windows as [$windowStart, $windowEnd]) {
            if ($start->gte($windowStart) && $end->lte($windowEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A candidate slot clashes with a booking when their buffer-expanded intervals
     * overlap (docs/04 §2). The service's buffers pad both sides.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    private function clashesWithBooking(Carbon $start, Carbon $end, Collection $bookings, Service $service): bool
    {
        $before = $service->booking_mode->supportsBuffers() ? $service->buffer_before_minutes : 0;
        $after = $service->booking_mode->supportsBuffers() ? $service->buffer_after_minutes : 0;

        $slotStart = $start->copy()->subMinutes($before);
        $slotEnd = $end->copy()->addMinutes($after);

        foreach ($bookings as $booking) {
            if ($booking->starts_at === null || $booking->ends_at === null) {
                continue;
            }
            $bookingStart = $booking->starts_at->copy()->subMinutes($before);
            $bookingEnd = $booking->ends_at->copy()->addMinutes($after);

            if ($slotStart->lt($bookingEnd) && $bookingStart->lt($slotEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cut [cutStart, cutEnd] out of each window, splitting where it lands inside.
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function subtractInterval(array $windows, Carbon $cutStart, Carbon $cutEnd): array
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
     * @param  list<int>  $resourceIds
     * @return Collection<int, Schedule>
     */
    private function loadSchedules(int $tenantId, string $type, array $resourceIds, Carbon $day): Collection
    {
        return Schedule::query()
            ->where('tenant_id', $tenantId)
            ->where('schedulable_type', $type)
            ->whereIn('schedulable_id', $resourceIds)
            ->where('day_of_week', $day->isoWeekday())
            ->get();
    }

    /**
     * @param  list<int>  $resourceIds
     * @return Collection<int, ScheduleException>
     */
    private function loadExceptions(int $tenantId, string $type, array $resourceIds, Carbon $day): Collection
    {
        return ScheduleException::query()
            ->where('tenant_id', $tenantId)
            ->where('schedulable_type', $type)
            ->whereIn('schedulable_id', $resourceIds)
            ->whereDate('date', $day->toDateString())
            ->get();
    }

    /**
     * Blocking bookings for the resources overlapping the day (± a margin for
     * buffers), grouped by resource id.
     *
     * @param  list<int>  $resourceIds
     * @return array<int, Collection<int, Booking>>
     */
    private function loadBookings(int $tenantId, string $type, array $resourceIds, Carbon $day): array
    {
        $column = $type === 'room' ? 'room_id' : 'staff_id';
        $dayStart = $day->copy()->utc()->subDay();
        $dayEnd = $day->copy()->addDays(2)->utc();

        $grouped = Booking::query()
            ->where('tenant_id', $tenantId)
            ->whereIn($column, $resourceIds)
            ->whereIn('status', BookingStatus::occupyingValues())
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get()
            ->groupBy($column);

        $result = [];
        foreach ($grouped as $key => $group) {
            $result[(int) $key] = $group->toBase();
        }

        return $result;
    }
}
