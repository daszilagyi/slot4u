<?php

namespace App\Services\Report;

/**
 * Everything the tenant statistics page renders (SLO-137, docs/05 M7). Assembled
 * by {@see BuildTenantReport}; the page itself does no arithmetic beyond turning
 * these numbers into bars.
 */
final class TenantReport
{
    /**
     * @param  list<array{date: string, revenue_minor: int, bookings: int}>  $series
     * @param  list<array{id: int|null, name: string|null, bookings: int, revenue_minor: int}>  $byService
     * @param  list<array{id: int, name: string, bookings: int, revenue_minor: int, booked_minutes: int, scheduled_minutes: int, utilization_bps: int|null}>  $byStaff
     * @param  list<array{id: int, name: string, bookings: int, revenue_minor: int, booked_minutes: int, scheduled_minutes: int, utilization_bps: int|null}>  $byRoom
     * @param  list<array{name: string, is_guest: bool, bookings: int, spend_minor: int}>  $topCustomers
     */
    public function __construct(
        public readonly string $preset,
        /** Inclusive local date bounds of the selected range. */
        public readonly string $from,
        public readonly string $to,
        /** The equally long range immediately before it. */
        public readonly string $previousFrom,
        public readonly string $previousTo,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly ReportTotals $totals,
        public readonly ReportTotals $previousTotals,
        public readonly array $series,
        public readonly array $byService,
        public readonly array $byStaff,
        public readonly array $byRoom,
        public readonly array $topCustomers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'previous_from' => $this->previousFrom,
            'previous_to' => $this->previousTo,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'totals' => $this->totals->toArray(),
            'previous_totals' => $this->previousTotals->toArray(),
            'series' => $this->series,
            'by_service' => $this->byService,
            'by_staff' => $this->byStaff,
            'by_room' => $this->byRoom,
            'top_customers' => $this->topCustomers,
        ];
    }
}
