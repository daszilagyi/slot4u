<?php

namespace App\Services\Dashboard;

/**
 * The tenant admin dashboard's read model (SLO-43, docs/05 M7). Every figure is
 * already resolved to the tenant's timezone and scoped to what the actor may see;
 * the blocks the actor has no permission for arrive as `null` so the page can drop
 * the tile rather than render a zero it is not allowed to know.
 */
final readonly class TenantDashboard
{
    /**
     * @param  string  $date  Tenant-local "today" (Y-m-d) the day figures cover.
     * @param  string  $calendarMonth  Tenant-local month (Y-m) the calendar covers.
     * @param  list<array<string, mixed>>|null  $agenda  Today's bookings, chronological.
     * @param  list<array<string, mixed>>|null  $recent  Most recently created bookings.
     * @param  list<array{date: string, count: int}>|null  $calendar  Per-day booking counts of the month.
     */
    public function __construct(
        public string $date,
        public string $calendarMonth,
        public string $timezone,
        public string $currency,
        public ?int $bookingsToday,
        public ?int $confirmedToday,
        public ?int $revenueTodayMinor,
        public ?int $pendingApproval,
        public ?int $pendingPayment,
        public ?int $customersTotal,
        public ?int $customersNewThisMonth,
        public ?array $agenda,
        public ?array $recent,
        public ?array $calendar,
    ) {}
}
