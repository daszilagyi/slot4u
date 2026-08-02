<?php

namespace App\Services\Dashboard;

use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BookingVisibility;
use App\Support\CustomerVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Assembles the tenant admin bento dashboard (SLO-43, docs/05 M7): today's
 * numbers, today's agenda, the freshest bookings and a month calendar of booking
 * counts.
 *
 * Two rules run through every query here. First, the day boundaries are the
 * tenant's wall clock (docs/01 §7) — "today" for a Budapest tenant is 22:00–22:00
 * UTC in winter — so the ranges are built in the tenant timezone and converted to
 * UTC instants for the stored columns. Second, the actor only ever sees their own
 * slice: {@see BookingVisibility} / {@see CustomerVisibility} narrow an employee to
 * their own staff records, exactly like the list pages, and a block the actor has
 * no permission for is skipped entirely (null) rather than rendered as a zero.
 */
class BuildTenantDashboard
{
    /** How many freshly created bookings the "latest" panel shows. */
    private const RECENT_LIMIT = 6;

    /**
     * How far the agenda lists today's bookings. A busy tenant can have hundreds in
     * a day and the dashboard must stay a glance, not a list page — the tile above
     * still carries the true total (`bookingsToday` counts exactly what the agenda
     * filters), so the page can say how many were left off and link to the list.
     */
    private const AGENDA_LIMIT = 12;

    /**
     * Money lives on the booking row, not on the tenant (docs/02) — a tenant with
     * no bookings yet has no currency of its own, so the tiles fall back to the
     * platform default, as the customer card does.
     */
    private const FALLBACK_CURRENCY = 'HUF';

    /**
     * Statuses whose price counts as turnover. Deliberately the commission-bearing
     * set of docs/10 §3.1 (a no-show is charged and does carry commission), so the
     * revenue tile never contradicts the tenant's own /billing page. The one case
     * it glosses over is the late cancellation, which stays billable there but is
     * not revenue the tenant is looking at on the day.
     *
     * @var list<BookingStatus>
     */
    private const REVENUE_STATUSES = [
        BookingStatus::Confirmed,
        BookingStatus::Completed,
        BookingStatus::NoShow,
    ];

    /** Statuses that no longer occupy the day (they are off the agenda). */
    private const DEAD_STATUSES = [
        BookingStatus::Canceled,
        BookingStatus::Rejected,
    ];

    public function __invoke(Tenant $tenant, User $actor, ?Carbon $now = null): TenantDashboard
    {
        $timezone = $tenant->timezone;
        $localNow = ($now ?? Carbon::now())->copy()->setTimezone($timezone);

        $dayStart = $localNow->copy()->startOfDay();
        $dayEnd = $localNow->copy()->endOfDay();
        $monthStart = $localNow->copy()->startOfMonth();
        $monthEnd = $localNow->copy()->endOfMonth();

        $seesBookings = $actor->can(Permission::BookingView->value);
        $seesCustomers = $actor->can(Permission::CustomerView->value);

        return new TenantDashboard(
            date: $dayStart->toDateString(),
            calendarMonth: $monthStart->format('Y-m'),
            timezone: $timezone,
            currency: $seesBookings ? $this->currency($actor) : self::FALLBACK_CURRENCY,
            bookingsToday: $seesBookings ? $this->countInDay($actor, $dayStart, $dayEnd) : null,
            confirmedToday: $seesBookings ? $this->countInDay($actor, $dayStart, $dayEnd, self::REVENUE_STATUSES) : null,
            revenueTodayMinor: $seesBookings ? $this->revenueInDay($actor, $dayStart, $dayEnd) : null,
            pendingApproval: $seesBookings ? $this->countWithStatus($actor, BookingStatus::Requested) : null,
            pendingPayment: $seesBookings ? $this->countWithStatus($actor, BookingStatus::PendingPayment) : null,
            customersTotal: $seesCustomers ? $this->countCustomers($actor) : null,
            customersNewThisMonth: $seesCustomers ? $this->countCustomers($actor, $monthStart) : null,
            agenda: $seesBookings ? $this->agenda($actor, $dayStart, $dayEnd) : null,
            recent: $seesBookings ? $this->recent($actor) : null,
            calendar: $seesBookings ? $this->calendar($actor, $monthStart, $monthEnd, $timezone) : null,
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
     * bookings when they are an employee.
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
     * Bookings that fall on a given local day. A timed booking is placed by
     * `starts_at`; a timeless one (the `no_time_slot` mode has no start) falls back
     * to when it was created, so it still shows up on the day it was taken. The two
     * branches stay separate rather than folding into a COALESCE so the common,
     * timed branch can still use the `starts_at` index.
     *
     * @param  Builder<Booking>  $query
     */
    private function inDay(Builder $query, Carbon $dayStart, Carbon $dayEnd): void
    {
        $fromUtc = $dayStart->copy()->utc();
        $toUtc = $dayEnd->copy()->utc();

        $query->where(fn (Builder $q) => $q
            ->whereBetween('starts_at', [$fromUtc, $toUtc])
            ->orWhere(fn (Builder $qq) => $qq
                ->whereNull('starts_at')
                ->whereBetween('created_at', [$fromUtc, $toUtc])));
    }

    /**
     * @param  list<BookingStatus>|null  $statuses  null → everything still alive today.
     */
    private function countInDay(User $actor, Carbon $dayStart, Carbon $dayEnd, ?array $statuses = null): int
    {
        $query = $this->visible($actor);
        $this->inDay($query, $dayStart, $dayEnd);

        if ($statuses === null) {
            $query->whereNotIn('status', array_map(fn (BookingStatus $s) => $s->value, self::DEAD_STATUSES));
        } else {
            $query->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, $statuses));
        }

        return $query->count();
    }

    private function revenueInDay(User $actor, Carbon $dayStart, Carbon $dayEnd): int
    {
        $query = $this->visible($actor)
            ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, self::REVENUE_STATUSES));
        $this->inDay($query, $dayStart, $dayEnd);

        return (int) $query->sum('price_minor');
    }

    /**
     * Open work items, deliberately not limited to today: a request that has been
     * waiting since last week is exactly what the tile must surface.
     */
    private function countWithStatus(User $actor, BookingStatus $status): int
    {
        return $this->visible($actor)->where('status', $status->value)->count();
    }

    /** @param  Carbon|null  $since  null → the whole roster. */
    private function countCustomers(User $actor, ?Carbon $since = null): int
    {
        $query = Customer::tenantScoped();
        CustomerVisibility::apply($query, $actor);

        if ($since !== null) {
            $query->where('users.created_at', '>=', $since->copy()->utc());
        }

        return $query->count();
    }

    /**
     * Today's timeline, chronological. Cancelled and rejected rows are dropped —
     * the panel answers "what happens today", not "what was ever booked for today".
     * The timeless bookings sort last (`starts_at is null` is 0/1 on both SQLite and
     * MariaDB) rather than first, which is where a plain ORDER BY would put them.
     *
     * @return list<array<string, mixed>>
     */
    private function agenda(User $actor, Carbon $dayStart, Carbon $dayEnd): array
    {
        $query = $this->visible($actor)
            ->with(['customer:id,name', 'service:id,name', 'staff:id,name'])
            ->whereNotIn('status', array_map(fn (BookingStatus $s) => $s->value, self::DEAD_STATUSES))
            ->orderByRaw('starts_at is null')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(self::AGENDA_LIMIT);
        $this->inDay($query, $dayStart, $dayEnd);

        return $query->get()->map(fn (Booking $booking) => $this->row($booking))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(User $actor): array
    {
        return $this->visible($actor)
            ->with(['customer:id,name', 'service:id,name', 'staff:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (Booking $booking) => $this->row($booking))
            ->all();
    }

    /**
     * Booking counts per day of the current month. The bucketing happens in PHP on
     * a single `starts_at`-only select rather than in a GROUP BY: the date-part SQL
     * differs between the SQLite test database and MariaDB in production, and the
     * grouping key has to be the *tenant-local* date anyway, which no portable SQL
     * expression can produce.
     *
     * @return list<array{date: string, count: int}>
     */
    private function calendar(User $actor, Carbon $monthStart, Carbon $monthEnd, string $timezone): array
    {
        $starts = $this->visible($actor)
            ->whereNotIn('status', array_map(fn (BookingStatus $s) => $s->value, self::DEAD_STATUSES))
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$monthStart->copy()->utc(), $monthEnd->copy()->utc()])
            ->pluck('starts_at');

        $counts = [];
        foreach ($starts as $start) {
            $date = $start->copy()->setTimezone($timezone)->toDateString();
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }

        $calendar = [];
        for ($day = $monthStart->copy(); $day->lessThanOrEqualTo($monthEnd); $day->addDay()) {
            $date = $day->toDateString();
            $calendar[] = ['date' => $date, 'count' => $counts[$date] ?? 0];
        }

        return $calendar;
    }

    /**
     * Row DTO for both booking panels. Mirrors the list page's summary shape so the
     * frontend can reuse the same badge and formatting helpers.
     *
     * @return array<string, mixed>
     */
    private function row(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'code' => $booking->code,
            // A guest booking (SLO-128) has no account behind it.
            'customer' => $booking->contactName(),
            'is_guest' => $booking->isGuest(),
            'service' => $booking->service?->name,
            'staff' => $booking->staff?->name,
            'status' => $booking->status->value,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'created_at' => $booking->created_at?->toIso8601String(),
            'price_minor' => (int) $booking->price_minor,
            'currency' => $booking->currency,
        ];
    }
}
