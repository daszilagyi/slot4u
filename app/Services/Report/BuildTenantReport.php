<?php

namespace App\Services\Report;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Schedule\WorkingWindows;
use App\Support\BookingVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The tenant statistics module (SLO-137 / SLO-45, docs/05 M7): what the selected
 * period earned, who earned it, and how much of the available time it used.
 *
 * Three rules govern every figure here.
 *
 * 1. **Money is the commission base.** Revenue counts the `confirmed + completed +
 *    no_show` bookings of docs/10 §3.1 — the same set the dashboard tile and the
 *    tenant's own /billing page use, so the three can never disagree.
 * 2. **Days are the tenant's wall clock** (docs/01 §7). Ranges are built from local
 *    dates and converted to UTC only where they meet the stored columns; the daily
 *    series is bucketed in PHP because the grouping key is a tenant-local date,
 *    which no portable SQL expression produces (and the date SQL differs between the
 *    SQLite test database and MariaDB in production).
 * 3. **Capacity comes from the booking engine's own rule.** Utilisation divides
 *    booked minutes by open minutes, and the open minutes come from
 *    {@see WorkingWindows} — the very code that decides which slots are bookable.
 *
 * Only tenant-admin and manager hold `report.view` (docs/03), and both see every
 * booking of the tenant; {@see BookingVisibility} is still applied so that a
 * hand-granted permission narrows the report instead of leaking colleagues' rows.
 */
class BuildTenantReport
{
    /** How many rows the customer spend toplist carries. */
    private const TOP_CUSTOMERS = 10;

    private const FALLBACK_CURRENCY = 'HUF';

    /**
     * Bookings that count as realised: they carry revenue, they occupied the
     * resource, and they are exactly the commission base (docs/10 §3.1). A no-show
     * belongs here — it was charged and it did block the slot.
     *
     * @var list<string>
     */
    private const REALIZED = [
        BookingStatus::Confirmed->value,
        BookingStatus::Completed->value,
        BookingStatus::NoShow->value,
    ];

    /**
     * @param  array<string, mixed>  $filters  validated ReportFilterRequest input
     */
    public function __invoke(Tenant $tenant, User $actor, array $filters, ?Carbon $now = null): TenantReport
    {
        $range = ReportRange::fromFilters($filters, $tenant->timezone, $now);
        $previous = $range->previous();

        return new TenantReport(
            preset: $range->preset,
            from: $range->start->toDateString(),
            to: $range->end->toDateString(),
            previousFrom: $previous->start->toDateString(),
            previousTo: $previous->end->toDateString(),
            timezone: $tenant->timezone,
            currency: $this->currency($actor),
            totals: $this->totals($actor, $range),
            previousTotals: $this->totals($actor, $previous),
            series: $this->series($actor, $range),
            byService: $this->byService($actor, $range),
            byStaff: $this->byResource($actor, $range, 'staff'),
            byRoom: $this->byResource($actor, $range, 'room'),
            topCustomers: $this->topCustomers($actor, $range),
        );
    }

    /** The currency the tenant actually trades in, taken from its latest booking. */
    private function currency(User $actor): string
    {
        $latest = $this->visible($actor)->orderByDesc('id')->value('currency');

        return is_string($latest) && $latest !== '' ? $latest : self::FALLBACK_CURRENCY;
    }

    /**
     * Base query: tenant-scoped (BelongsToTenant) and narrowed to the actor's own
     * bookings if they ever turn out to be restricted.
     *
     * @return Builder<Booking>
     */
    private function visible(User $actor): Builder
    {
        $query = Booking::query();
        BookingVisibility::apply($query, $actor);

        return $query;
    }

    /**
     * Bookings dated inside the range. A timed booking is placed by `starts_at`; a
     * timeless one (`no_time_slot`, docs/04 §1) falls back to when it was taken. The
     * two branches stay separate rather than folding into a COALESCE so the common,
     * timed branch can still use the `starts_at` index.
     *
     * @return Builder<Booking>
     */
    private function inRange(User $actor, ReportRange $range): Builder
    {
        $from = $range->startUtc();
        $to = $range->endExclusiveUtc();

        return $this->visible($actor)->where(fn (Builder $q) => $q
            ->where(fn (Builder $qq) => $qq
                ->where('starts_at', '>=', $from)
                ->where('starts_at', '<', $to))
            ->orWhere(fn (Builder $qq) => $qq
                ->whereNull('starts_at')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)));
    }

    /**
     * Headline figures, from one grouped query per period. Both the selected and the
     * comparison range run through here, so a delta always compares like with like.
     */
    private function totals(User $actor, ReportRange $range): ReportTotals
    {
        /** @var Collection<int, object{status: BookingStatus, bookings: int, revenue: int|string|null}> $rows */
        $rows = $this->inRange($actor, $range)
            ->selectRaw('status, count(*) as bookings, sum(price_minor) as revenue')
            ->groupBy('status')
            ->get();

        $counts = [];
        $revenue = 0;
        foreach ($rows as $row) {
            // The rows come back as Booking models, so `status` arrives through the
            // model's cast as a BookingStatus rather than as the raw string.
            $status = $row->status->value;
            $counts[$status] = (int) $row->bookings;

            if (in_array($status, self::REALIZED, true)) {
                $revenue += (int) $row->revenue;
            }
        }

        $realized = 0;
        foreach (self::REALIZED as $status) {
            $realized += $counts[$status] ?? 0;
        }

        return new ReportTotals(
            bookings: array_sum($counts),
            realized: $realized,
            canceled: $counts[BookingStatus::Canceled->value] ?? 0,
            noShow: $counts[BookingStatus::NoShow->value] ?? 0,
            revenueMinor: $revenue,
            customers: $this->distinctCustomers($actor, $range),
        );
    }

    /**
     * Distinct contacts behind the realised bookings. Accounts and guests are counted
     * separately because a guest booking has no `customer_id` at all (SLO-128) — the
     * same person booking once with an account and once as a guest counts twice, which
     * is the honest answer given the tenant has no way to link them either.
     */
    private function distinctCustomers(User $actor, ReportRange $range): int
    {
        $accounts = $this->inRange($actor, $range)
            ->whereIn('status', self::REALIZED)
            ->whereNotNull('customer_id')
            ->distinct()
            ->count('customer_id');

        $guests = $this->inRange($actor, $range)
            ->whereIn('status', self::REALIZED)
            ->whereNull('customer_id')
            ->whereNotNull('guest_email')
            ->distinct()
            ->count('guest_email');

        return (int) $accounts + (int) $guests;
    }

    /**
     * Revenue and booking count per local day, zero-filled so the chart has a bar for
     * every day rather than a gap the reader has to interpret.
     *
     * @return list<array{date: string, revenue_minor: int, bookings: int}>
     */
    private function series(User $actor, ReportRange $range): array
    {
        $rows = $this->inRange($actor, $range)
            ->whereIn('status', self::REALIZED)
            ->get(['starts_at', 'created_at', 'price_minor']);

        $buckets = [];
        foreach ($rows as $row) {
            $at = $row->starts_at ?? $row->created_at;
            if ($at === null) {
                continue;
            }
            $date = $at->copy()->setTimezone($range->timezone)->toDateString();
            $buckets[$date]['revenue'] = ($buckets[$date]['revenue'] ?? 0) + (int) $row->price_minor;
            $buckets[$date]['bookings'] = ($buckets[$date]['bookings'] ?? 0) + 1;
        }

        $series = [];
        foreach ($range->days() as $day) {
            $date = $day->toDateString();
            $series[] = [
                'date' => $date,
                'revenue_minor' => (int) ($buckets[$date]['revenue'] ?? 0),
                'bookings' => (int) ($buckets[$date]['bookings'] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Realised bookings and revenue per service. Both columns are the realised set,
     * not "all bookings + realised revenue" — a mixed pair would invite dividing one
     * by the other and getting a number that means nothing.
     *
     * @return list<array{id: int, name: string|null, bookings: int, revenue_minor: int}>
     */
    private function byService(User $actor, ReportRange $range): array
    {
        /** @var Collection<int, object{service_id: int|string, bookings: int, revenue: int|string|null}> $rows */
        $rows = $this->inRange($actor, $range)
            ->whereIn('status', self::REALIZED)
            ->selectRaw('service_id, count(*) as bookings, sum(price_minor) as revenue')
            ->groupBy('service_id')
            ->get();

        $names = Service::query()
            ->whereIn('id', $rows->pluck('service_id')->all())
            ->pluck('name', 'id');

        // A service deleted out from under its bookings leaves the row nameless
        // rather than dropping the revenue it earned.
        $result = $rows->map(fn (object $row) => [
            'id' => (int) $row->service_id,
            'name' => $names[(int) $row->service_id] ?? null,
            'bookings' => (int) $row->bookings,
            'revenue_minor' => (int) $row->revenue,
        ])->all();

        usort($result, fn (array $a, array $b) => $b['revenue_minor'] <=> $a['revenue_minor']);

        return $result;
    }

    /**
     * Per-resource activity: realised bookings, revenue, booked minutes, and the open
     * minutes they used up.
     *
     * A resource with no schedule and no bookings in the range is dropped — the panel
     * answers "how did the period go", not "who exists".
     *
     * @param  'staff'|'room'  $type
     * @return list<array{id: int, name: string, bookings: int, revenue_minor: int, booked_minutes: int, scheduled_minutes: int, utilization_bps: int|null}>
     */
    private function byResource(User $actor, ReportRange $range, string $type): array
    {
        $column = $type === 'room' ? 'room_id' : 'staff_id';

        /** @var Collection<int, object{resource_id: int|string|null, bookings: int, revenue: int|string|null}> $rows */
        $rows = $this->inRange($actor, $range)
            ->whereIn('status', self::REALIZED)
            ->whereNotNull($column)
            ->selectRaw($column.' as resource_id, count(*) as bookings, sum(price_minor) as revenue')
            ->groupBy($column)
            ->get();

        $names = $this->resourceNames($actor, $type);
        if ($names->isEmpty()) {
            return [];
        }

        $booked = $this->bookedMinutes($actor, $range, $column);
        $scheduled = $this->scheduledMinutes($range, $type, array_map('intval', $names->keys()->all()));

        $result = [];
        foreach ($names as $id => $name) {
            $id = (int) $id;
            $row = $rows->firstWhere('resource_id', $id);
            $bookedMinutes = $booked[$id] ?? 0;
            $scheduledMinutes = $scheduled[$id] ?? 0;

            if ($row === null && $bookedMinutes === 0 && $scheduledMinutes === 0) {
                continue;
            }

            $result[] = [
                'id' => $id,
                'name' => (string) $name,
                'bookings' => $row !== null ? (int) $row->bookings : 0,
                'revenue_minor' => $row !== null ? (int) $row->revenue : 0,
                'booked_minutes' => $bookedMinutes,
                'scheduled_minutes' => $scheduledMinutes,
                // No open hours at all → no utilisation, not 0% and not 100%: the
                // ratio is undefined and the UI must say so rather than imply idleness.
                'utilization_bps' => $scheduledMinutes > 0
                    ? intdiv($bookedMinutes * 10000, $scheduledMinutes)
                    : null,
            ];
        }

        usort($result, fn (array $a, array $b) => $b['booked_minutes'] <=> $a['booked_minutes']);

        return $result;
    }

    /**
     * The resources a panel may list, id => name.
     *
     * Both holders of `report.view` see the whole tenant, so this is normally every
     * staff member and room. A hand-narrowed actor gets only their own staff records
     * and no room panel at all: their booking rows are already filtered, so a room's
     * "booked minutes" would be a fraction of the truth divided by the room's full
     * opening hours — a utilisation figure that is not wrong so much as meaningless.
     *
     * @param  'staff'|'room'  $type
     * @return Collection<int, string>
     */
    private function resourceNames(User $actor, string $type): Collection
    {
        if ($type === 'room') {
            return BookingVisibility::unrestricted($actor)
                ? Room::query()->orderBy('name')->pluck('name', 'id')
                : new Collection;
        }

        $query = Staff::query()->orderBy('name');

        if (! BookingVisibility::unrestricted($actor)) {
            $query->whereIn('id', BookingVisibility::actorStaffIds($actor));
        }

        return $query->pluck('name', 'id');
    }

    /**
     * Minutes the realised bookings occupied per resource, clipped to the range so a
     * booking straddling the boundary contributes only the part inside it. Real
     * elapsed minutes (the columns are UTC instants), which is what capacity is
     * measured in.
     *
     * @param  'staff_id'|'room_id'  $column
     * @return array<int, int>
     */
    private function bookedMinutes(User $actor, ReportRange $range, string $column): array
    {
        $from = $range->startUtc();
        $to = $range->endExclusiveUtc();

        $rows = $this->visible($actor)
            ->whereIn('status', self::REALIZED)
            ->whereNotNull($column)
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            // Overlap, not containment: a booking that starts before the range and
            // ends inside it used part of this period's capacity.
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get([$column, 'starts_at', 'ends_at']);

        $minutes = [];
        foreach ($rows as $row) {
            $start = $row->starts_at?->lt($from) ? $from->copy() : $row->starts_at;
            $end = $row->ends_at?->gt($to) ? $to->copy() : $row->ends_at;

            if ($start === null || $end === null || $end->lte($start)) {
                continue;
            }

            $id = (int) $row->getAttribute($column);
            $minutes[$id] = ($minutes[$id] ?? 0) + (int) $start->diffInMinutes($end);
        }

        return $minutes;
    }

    /**
     * Open minutes per resource across the range, from the weekly bands and their
     * date exceptions — the booking engine's own definition of "open"
     * ({@see WorkingWindows}).
     *
     * The bands and exceptions are loaded once for the whole range and matched in
     * memory: a per-day query pair would be two round-trips times up to a year of
     * days, while a tenant's whole schedule is a few dozen rows.
     *
     * @param  'staff'|'room'  $type
     * @param  list<int>  $resourceIds
     * @return array<int, int>
     */
    private function scheduledMinutes(ReportRange $range, string $type, array $resourceIds): array
    {
        if ($resourceIds === []) {
            return [];
        }

        $schedules = Schedule::query()
            ->where('schedulable_type', $type)
            ->whereIn('schedulable_id', $resourceIds)
            ->get();

        $exceptions = ScheduleException::query()
            ->where('schedulable_type', $type)
            ->whereIn('schedulable_id', $resourceIds)
            ->whereBetween('date', [$range->start->toDateString(), $range->end->toDateString()])
            ->get();

        if ($schedules->isEmpty() && $exceptions->isEmpty()) {
            return [];
        }

        $minutes = [];
        foreach ($range->days() as $day) {
            foreach ($resourceIds as $id) {
                $windows = WorkingWindows::forDay($type, $id, $day, $range->timezone, $schedules, $exceptions);
                $open = WorkingWindows::minutes($windows);

                if ($open > 0) {
                    $minutes[$id] = ($minutes[$id] ?? 0) + $open;
                }
            }
        }

        return $minutes;
    }

    /**
     * Who spent the most in the range. Account holders and guests are two grouped
     * queries merged in PHP — a guest booking carries its contact on the booking row
     * itself, so there is no single column to group both by.
     *
     * @return list<array{name: string, is_guest: bool, bookings: int, spend_minor: int}>
     */
    private function topCustomers(User $actor, ReportRange $range): array
    {
        /** @var Collection<int, object{customer_id: int|string, bookings: int, spend: int|string|null}> $accounts */
        $accounts = $this->inRange($actor, $range)
            ->whereIn('status', self::REALIZED)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, count(*) as bookings, sum(price_minor) as spend')
            ->groupBy('customer_id')
            ->orderByDesc('spend')
            ->limit(self::TOP_CUSTOMERS)
            ->get();

        $names = User::query()
            ->whereIn('id', $accounts->pluck('customer_id')->all())
            ->pluck('name', 'id');

        /** @var Collection<int, object{guest_email: string, guest_name: string|null, bookings: int, spend: int|string|null}> $guests */
        $guests = $this->inRange($actor, $range)
            ->whereIn('status', self::REALIZED)
            ->whereNull('customer_id')
            ->whereNotNull('guest_email')
            ->selectRaw('guest_email, min(guest_name) as guest_name, count(*) as bookings, sum(price_minor) as spend')
            ->groupBy('guest_email')
            ->orderByDesc('spend')
            ->limit(self::TOP_CUSTOMERS)
            ->get();

        $result = [];

        foreach ($accounts as $row) {
            $result[] = [
                'name' => (string) ($names[(int) $row->customer_id] ?? '—'),
                'is_guest' => false,
                'bookings' => (int) $row->bookings,
                'spend_minor' => (int) $row->spend,
            ];
        }

        foreach ($guests as $row) {
            $result[] = [
                'name' => $row->guest_name !== null && $row->guest_name !== '' ? $row->guest_name : $row->guest_email,
                'is_guest' => true,
                'bookings' => (int) $row->bookings,
                'spend_minor' => (int) $row->spend,
            ];
        }

        usort($result, fn (array $a, array $b) => $b['spend_minor'] <=> $a['spend_minor']);

        return array_slice($result, 0, self::TOP_CUSTOMERS);
    }
}
