<?php

namespace App\Services\Calendar;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BookingVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the admin calendar (SLO-44, docs/05 M7): a day or week time grid of the
 * tenant's bookings, split into resource columns and filterable by staff, room and
 * service.
 *
 * Positions are **wall-clock** minutes from the tenant-local midnight of the
 * booking's own day, not elapsed minutes. That is deliberate: a calendar shows the
 * clock on the wall, so a 10:00 appointment belongs on the 10:00 row even on the
 * spring-forward day when only 9 real hours have passed since midnight. The whole
 * conversion happens here, so the frontend positions events with plain arithmetic
 * and cannot re-introduce a timezone error while doing it.
 *
 * Scoping is the same as the list page: tenant-isolated by the global scope, then
 * narrowed by {@see BookingVisibility} so an employee only ever sees their own.
 */
class BuildBookingCalendar
{
    /** Default visible window, 06:00–22:00, widened to fit anything outside it. */
    private const DEFAULT_WINDOW_START = 6 * 60;

    private const DEFAULT_WINDOW_END = 22 * 60;

    private const MINUTES_PER_DAY = 24 * 60;

    /** Statuses that no longer occupy the grid. */
    private const DEAD_STATUSES = [
        BookingStatus::Canceled,
        BookingStatus::Rejected,
    ];

    /**
     * @param  array{view?: string|null, date?: string|null, group?: string|null, staff_id?: int|null, room_id?: int|null, service_id?: int|null}  $filters
     */
    public function __invoke(Tenant $tenant, User $actor, array $filters, ?Carbon $now = null): BookingCalendar
    {
        $timezone = $tenant->timezone;
        $localNow = ($now ?? Carbon::now())->copy()->setTimezone($timezone);

        $view = ($filters['view'] ?? null) === 'day' ? 'day' : 'week';
        $group = ($filters['group'] ?? null) === 'room' ? 'room' : 'staff';
        $anchor = $this->anchor($filters['date'] ?? null, $timezone, $localNow);

        // Weeks start on Monday (Hungarian convention), stated explicitly rather than
        // left to whatever Carbon's locale default happens to be.
        [$rangeStart, $rangeEnd] = $view === 'day'
            ? [$anchor->copy(), $anchor->copy()]
            : [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)];

        $bookings = $this->bookings($actor, $rangeStart, $rangeEnd, $filters);

        $events = [];
        $unscheduled = [];
        foreach ($bookings as $booking) {
            if ($booking->starts_at === null) {
                $unscheduled[] = $this->row($booking, $timezone);

                continue;
            }

            $events[] = $this->event($booking, $timezone, $view, $group);
        }

        return new BookingCalendar(
            view: $view,
            date: $anchor->toDateString(),
            rangeStart: $rangeStart->toDateString(),
            rangeEnd: $rangeEnd->toDateString(),
            prevDate: $anchor->copy()->subDays($view === 'day' ? 1 : 7)->toDateString(),
            nextDate: $anchor->copy()->addDays($view === 'day' ? 1 : 7)->toDateString(),
            today: $localNow->toDateString(),
            timezone: $timezone,
            windowStartMinute: $this->windowStart($events),
            windowEndMinute: $this->windowEnd($events),
            columns: $view === 'day'
                ? $this->resourceColumns($actor, $group, $events)
                : $this->dayColumns($rangeStart, $rangeEnd),
            events: $events,
            unscheduled: $unscheduled,
        );
    }

    /** The day the grid is centred on: the requested one, or today. */
    private function anchor(?string $date, string $timezone, Carbon $localNow): Carbon
    {
        if ($date === null) {
            return $localNow->copy()->startOfDay();
        }

        return Carbon::parse($date, $timezone)->startOfDay();
    }

    /**
     * The bookings of the range, dead statuses dropped — the grid answers "what is
     * happening", not "what was ever booked". A booking with no start time cannot be
     * placed on the grid but is still in range, so it is collected separately rather
     * than silently dropped.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Booking>
     */
    private function bookings(User $actor, Carbon $rangeStart, Carbon $rangeEnd, array $filters): Collection
    {
        $fromUtc = $rangeStart->copy()->startOfDay()->utc();
        $toUtc = $rangeEnd->copy()->endOfDay()->utc();

        $query = Booking::query()
            ->with(['customer:id,name', 'service:id,name', 'staff:id,name', 'room:id,name'])
            ->whereNotIn('status', array_map(fn (BookingStatus $s) => $s->value, self::DEAD_STATUSES))
            ->where(fn (Builder $q) => $q
                ->whereBetween('starts_at', [$fromUtc, $toUtc])
                ->orWhere(fn (Builder $qq) => $qq
                    ->whereNull('starts_at')
                    ->whereBetween('created_at', [$fromUtc, $toUtc])))
            ->when($filters['staff_id'] ?? null, fn (Builder $q, int $id) => $q->where('staff_id', $id))
            ->when($filters['room_id'] ?? null, fn (Builder $q, int $id) => $q->where('room_id', $id))
            ->when($filters['service_id'] ?? null, fn (Builder $q, int $id) => $q->where('service_id', $id))
            ->orderBy('starts_at')
            ->orderBy('id');

        // Employees see only their own bookings (docs/03 "saját foglalásai").
        BookingVisibility::apply($query, $actor);

        return $query->get();
    }

    /**
     * Place a booking on the grid. `column_key` says which column it belongs to: the
     * day in week view, the grouping resource in day view. An unassigned booking gets
     * the `staff-none` / `room-none` column so it stays visible instead of vanishing.
     *
     * A booking running past midnight is clamped to the bottom of its start day —
     * multi-day rendering is not worth the complexity here, and `ends_at` travels
     * with the row so the card can still state the real end.
     *
     * @return array<string, mixed>
     */
    private function event(Booking $booking, string $timezone, string $view, string $group): array
    {
        $start = $booking->starts_at->copy()->setTimezone($timezone);
        $end = $booking->ends_at?->copy()->setTimezone($timezone);

        $date = $start->toDateString();
        $startMinute = $start->hour * 60 + $start->minute;
        $endMinute = $end !== null && $end->toDateString() === $date
            ? $end->hour * 60 + $end->minute
            : self::MINUTES_PER_DAY;

        $resourceId = $group === 'room' ? $booking->room_id : $booking->staff_id;

        return $this->row($booking, $timezone) + [
            'date' => $date,
            'column_key' => $view === 'week'
                ? $date
                : $group.'-'.($resourceId !== null ? (string) $resourceId : 'none'),
            'start_minute' => $startMinute,
            // A zero-length booking would collapse to an invisible sliver.
            'end_minute' => max($endMinute, $startMinute + 15),
        ];
    }

    /**
     * Row DTO shared by the grid events and the unscheduled list.
     *
     * @return array<string, mixed>
     */
    private function row(Booking $booking, string $timezone): array
    {
        return [
            'id' => $booking->id,
            'code' => $booking->code,
            // A guest booking (SLO-128) has no account behind it.
            'customer' => $booking->contactName(),
            'is_guest' => $booking->isGuest(),
            'service' => $booking->service?->name,
            'staff' => $booking->staff?->name,
            'room' => $booking->room?->name,
            'status' => $booking->status->value,
            'booking_mode' => $booking->booking_mode->value,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'party_size' => (int) $booking->party_size,
        ];
    }

    /**
     * Day-view columns: one per resource the actor may see, plus an "unassigned"
     * column — but only when something actually lands in it, so tenants that always
     * assign never carry a permanently empty column.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array{key: string, label: string, sublabel: string|null, date: string|null}>
     */
    private function resourceColumns(User $actor, string $group, array $events): array
    {
        $resources = $group === 'room'
            ? Room::query()->orderBy('name')->get(['id', 'name'])
            : $this->visibleStaff($actor);

        $columns = $resources->map(fn ($resource) => [
            'key' => $group.'-'.$resource->id,
            'label' => (string) $resource->name,
            'sublabel' => null,
            'date' => null,
        ])->all();

        $unassignedKey = $group.'-none';
        $hasUnassigned = collect($events)->contains(fn (array $event) => $event['column_key'] === $unassignedKey);

        if ($hasUnassigned) {
            $columns[] = [
                'key' => $unassignedKey,
                'label' => __('app.admin.calendar.unassigned'),
                'sublabel' => null,
                'date' => null,
            ];
        }

        return array_values($columns);
    }

    /**
     * Staff the actor may see: everyone for the unrestricted roles, only their own
     * records for an employee — mirroring the booking scope, so the grid never shows
     * a column an employee can have no bookings in.
     *
     * @return Collection<int, Staff>
     */
    private function visibleStaff(User $actor): Collection
    {
        $query = Staff::query()->orderBy('name');

        if (! BookingVisibility::unrestricted($actor)) {
            $query->whereIn('id', BookingVisibility::actorStaffIds($actor));
        }

        return $query->get(['id', 'name']);
    }

    /**
     * Week-view columns: the seven days of the range. Iterating day by day (rather
     * than adding 24h) keeps the DST-shifted day a day.
     *
     * @return list<array{key: string, label: string, sublabel: string|null, date: string|null}>
     */
    private function dayColumns(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $columns = [];
        for ($day = $rangeStart->copy(); $day->lessThanOrEqualTo($rangeEnd); $day->addDay()) {
            $date = $day->toDateString();
            $columns[] = [
                'key' => $date,
                // The frontend localizes both; the server only says which day it is.
                'label' => (string) $day->isoWeekday(),
                'sublabel' => $date,
                'date' => $date,
            ];
        }

        return $columns;
    }

    /**
     * Grid top: the default, pulled up (to the hour) if something starts earlier. The
     * window is never allowed to hide a booking — an 05:00 start widens the grid
     * rather than falling off it.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function windowStart(array $events): int
    {
        $earliest = collect($events)->min('start_minute');

        if ($earliest === null || $earliest >= self::DEFAULT_WINDOW_START) {
            return self::DEFAULT_WINDOW_START;
        }

        return max(0, intdiv((int) $earliest, 60) * 60);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function windowEnd(array $events): int
    {
        $latest = collect($events)->max('end_minute');

        if ($latest === null || $latest <= self::DEFAULT_WINDOW_END) {
            return self::DEFAULT_WINDOW_END;
        }

        return min(self::MINUTES_PER_DAY, (int) ceil((int) $latest / 60) * 60);
    }
}
