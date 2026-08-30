<?php

namespace App\Services\Booking;

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Services\Schedule\WorkingWindows;
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
 * are natural) and every returned Slot carries UTC instants (docs/01 §7).
 *
 * Loading is per RANGE, not per day (SLO-83): schedules, exceptions and bookings
 * are fetched once for the whole span and sliced in memory. A day used to cost
 * three queries of its own, so the month view behind the public slot picker ran
 * ~90 — N+1-free within a day and N+1-shaped across them. `slotsForDay` is now
 * the one-day case of `slotsForRange`, so there is a single path to keep correct.
 *
 * Every query is anchored to the service's tenant explicitly, independent of the
 * ambient TenantScope (defense-in-depth for non-request callers — queue jobs, the
 * Phase-2 API).
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
        return $this->slotsForRange($service, $date, $date, $staffId, $roomId, $locationId);
    }

    /**
     * Free slots across an inclusive date range (tenant-local days).
     *
     * @return list<Slot>
     */
    public function slotsForRange(Service $service, Carbon $from, Carbon $to, ?int $staffId = null, ?int $roomId = null, ?int $locationId = null): array
    {
        if (! in_array($service->booking_mode, [BookingMode::DurationBased, BookingMode::ResourceRental], true)) {
            return [];
        }

        $timezone = $service->tenant->timezone;
        $settings = TenantSettings::fromArray($service->tenant->settings);
        $duration = $this->effectiveDuration($service, $settings);

        if ($duration <= 0) {
            return [];
        }

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

        $tenantId = (int) $service->tenant_id;
        $firstDay = $from->copy()->timezone($timezone)->startOfDay();
        $lastDay = $to->copy()->timezone($timezone)->startOfDay();

        if ($firstDay->gt($lastDay)) {
            return [];
        }

        // One load for the whole span. WorkingWindows::forDay already narrows by
        // weekday and exception date in memory, so the day loop can be handed the
        // same collections rather than a query each.
        $schedules = $this->loadSchedules($tenantId, $type, $resourceIds);
        $exceptions = $this->loadExceptions($tenantId, $type, $resourceIds, $firstDay, $lastDay);
        $bookings = $this->loadBookings($tenantId, $type, $resourceIds, $firstDay, $lastDay);

        // For a duration-based service that also pins a room, the room must be free.
        $roomSchedules = null;
        $roomExceptions = null;
        if ($type === 'staff' && $roomId !== null) {
            $roomSchedules = $this->loadSchedules($tenantId, 'room', [$roomId]);
            $roomExceptions = $this->loadExceptions($tenantId, 'room', [$roomId], $firstDay, $lastDay);
        }

        $slots = [];
        $cursor = $firstDay->copy();

        while ($cursor->lte($lastDay)) {
            array_push($slots, ...$this->slotsForLoadedDay(
                $service, $cursor->copy(), $staffId, $roomId, $locationId,
                $type, $resourceIds, $timezone, $settings->slotIntervalMinutes, $duration,
                $schedules, $exceptions, $bookings, $roomSchedules, $roomExceptions,
            ));
            $cursor->addDay();
        }

        return $slots;
    }

    /**
     * One day's slots from data already in memory — the body of the old
     * slotsForDay, minus its three queries.
     *
     * @param  list<int>  $resourceIds
     * @param  Collection<int, Schedule>  $schedules
     * @param  Collection<int, ScheduleException>  $exceptions
     * @param  array<int, Collection<int, Booking>>  $bookings
     * @param  Collection<int, Schedule>|null  $roomSchedules
     * @param  Collection<int, ScheduleException>|null  $roomExceptions
     * @return list<Slot>
     */
    private function slotsForLoadedDay(
        Service $service,
        Carbon $day,
        ?int $staffId,
        ?int $roomId,
        ?int $locationId,
        string $type,
        array $resourceIds,
        string $timezone,
        int $interval,
        int $duration,
        Collection $schedules,
        Collection $exceptions,
        array $bookings,
        ?Collection $roomSchedules,
        ?Collection $roomExceptions,
    ): array {
        $roomWindows = null;
        if ($roomSchedules !== null && $roomExceptions !== null && $roomId !== null) {
            $roomWindows = $this->freeWindows('room', $roomId, $day, $timezone, $roomSchedules, $roomExceptions);
        }

        $slots = [];
        $seenStarts = [];

        foreach ($resourceIds as $resourceId) {
            $windows = $this->freeWindows($type, $resourceId, $day, $timezone, $schedules, $exceptions, $locationId);
            $resourceBookings = $this->bookingsTouchingDay($bookings[$resourceId] ?? collect(), $day);

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
     * The range-loaded bookings that could touch this day, keeping the day's clash
     * check the same size it was before the range load (a month of one resource's
     * bookings compared against every slot would trade queries for CPU).
     *
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Booking>
     */
    private function bookingsTouchingDay(Collection $bookings, Carbon $day): Collection
    {
        $from = $day->copy()->utc()->subDay();
        $to = $day->copy()->addDays(2)->utc();

        return $bookings->filter(
            fn (Booking $booking) => $booking->starts_at !== null
                && $booking->ends_at !== null
                && $booking->starts_at->lt($to)
                && $booking->ends_at->gt($from)
        );
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

        $schedules = $this->loadSchedules($tenantId, 'room', [$roomId]);
        $exceptions = $this->loadExceptions($tenantId, 'room', [$roomId], $day, $day);
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

        $bookings = $this->loadBookings($tenantId, 'room', [$roomId], $day, $day)[$roomId] ?? collect();
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
     * Free local-time windows for one resource on the date. The rule itself lives in
     * {@see WorkingWindows} because the utilisation report needs the same capacity
     * definition (SLO-137); this stays as the engine's own name for it.
     *
     * @param  Collection<int, Schedule>  $schedules  all loaded bands, keyed by nothing
     * @param  Collection<int, ScheduleException>  $exceptions
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function freeWindows(string $type, int $resourceId, Carbon $day, string $timezone, Collection $schedules, Collection $exceptions, ?int $locationId = null): array
    {
        return WorkingWindows::forDay($type, $resourceId, $day, $timezone, $schedules, $exceptions, $locationId);
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
     * overlap (docs/04 §2).
     *
     * ⚠️ Each interval is padded with ITS OWN service's buffers (SLO-83). The
     * candidate used to lend its buffers to the booking as well, which is only
     * ever right when both belong to the same service. When one staff member or
     * one room serves two services, a 30-minute cleanup on the existing booking
     * was silently replaced by the candidate service's — often zero — and the
     * engine offered a slot that starts inside somebody's cleanup.
     *
     * The gap this demands between a booking and the next slot is therefore
     * `that booking's after` + `this service's before`: the room is cleaned after
     * one, prepared before the other. Two buffers of the same service still
     * behave exactly as they did, which is why the existing expectations hold.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    private function clashesWithBooking(Carbon $start, Carbon $end, Collection $bookings, Service $service): bool
    {
        [$before, $after] = $this->buffersOf($service);

        $slotStart = $start->copy()->subMinutes($before);
        $slotEnd = $end->copy()->addMinutes($after);

        foreach ($bookings as $booking) {
            if ($booking->starts_at === null || $booking->ends_at === null) {
                continue;
            }

            [$ownBefore, $ownAfter] = $this->buffersOf($booking->service);

            $bookingStart = $booking->starts_at->copy()->subMinutes($ownBefore);
            $bookingEnd = $booking->ends_at->copy()->addMinutes($ownAfter);

            if ($slotStart->lt($bookingEnd) && $bookingStart->lt($slotEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A service's [before, after] buffer in minutes, zero for a mode that has no
     * buffers and for a booking whose service row is gone.
     *
     * @return array{0: int, 1: int}
     */
    private function buffersOf(?Service $service): array
    {
        if ($service === null || ! $service->booking_mode->supportsBuffers()) {
            return [0, 0];
        }

        return [$service->buffer_before_minutes, $service->buffer_after_minutes];
    }

    /**
     * Every weekday band for the resources — the range may cover any weekday, and
     * WorkingWindows::forDay picks the day's own out of the collection. A weekly
     * schedule is at most a handful of rows per resource, so loading all seven is
     * cheaper than a query per day.
     *
     * @param  list<int>  $resourceIds
     * @return Collection<int, Schedule>
     */
    private function loadSchedules(int $tenantId, string $type, array $resourceIds): Collection
    {
        return Schedule::query()
            ->where('tenant_id', $tenantId)
            ->where('schedulable_type', $type)
            ->whereIn('schedulable_id', $resourceIds)
            ->get();
    }

    /**
     * @param  list<int>  $resourceIds
     * @return Collection<int, ScheduleException>
     */
    private function loadExceptions(int $tenantId, string $type, array $resourceIds, Carbon $from, Carbon $to): Collection
    {
        return ScheduleException::query()
            ->where('tenant_id', $tenantId)
            ->where('schedulable_type', $type)
            ->whereIn('schedulable_id', $resourceIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();
    }

    /**
     * Blocking bookings for the resources overlapping the range (± a margin for
     * buffers), grouped by resource id.
     *
     * The booking's own service comes with it: the clash check needs THAT
     * service's buffers, not only the candidate's (SLO-83).
     *
     * @param  list<int>  $resourceIds
     * @return array<int, Collection<int, Booking>>
     */
    private function loadBookings(int $tenantId, string $type, array $resourceIds, Carbon $from, Carbon $to): array
    {
        $column = $type === 'room' ? 'room_id' : 'staff_id';
        $dayStart = $from->copy()->utc()->subDay();
        $dayEnd = $to->copy()->addDays(2)->utc();

        // ⚠️ The lower bound is what makes this query indexable (SLO-176).
        //
        // The overlap test on its own — `starts_at < end AND ends_at > start` —
        // has no floor under `starts_at`, so no index can serve it and the
        // database must consider every booking the tenant has ever made.
        // Measured on 55,000 bookings that was a full table scan of 54,475 rows
        // on the busiest public endpoint: ~400 ms of a ~500 ms page. With this
        // bound it is a range scan of ~745 rows on `(tenant_id, starts_at)`
        // (docs/17 §10).
        //
        // The bound is CORRECT rather than convenient: nothing may span longer
        // than `booking.max_span_hours`, because Admin\BookingRequest refuses it.
        // A booking that began before this floor and is still running would be
        // invisible here — and an invisible booking is a slot offered twice — so
        // the guarantee is enforced at the write, not assumed at the read.
        $earliest = $dayStart->copy()->subHours((int) config('booking.max_span_hours', 24));

        $grouped = Booking::query()
            ->with('service:id,booking_mode,buffer_before_minutes,buffer_after_minutes')
            ->where('tenant_id', $tenantId)
            ->whereIn($column, $resourceIds)
            ->whereIn('status', BookingStatus::occupyingValues())
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', $earliest)
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
