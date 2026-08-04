<?php

namespace App\Services\Report;

use Illuminate\Support\Carbon;

/**
 * The reporting window, in the tenant's wall clock (SLO-137, docs/01 §7). "August"
 * for a Budapest tenant starts at 22:00 UTC on July 31, so every range is built
 * from local dates and only converted to UTC instants where it meets the stored
 * columns.
 *
 * The comparison window follows the shape of the selected one. A calendar preset
 * shifts back by a whole month or year — July compares against June, and a partial
 * August against the same days of July (month-to-date against month-to-date, never
 * against a full month, which would make every delta read as a collapse). A rolling
 * or hand-picked range compares against the equally long range immediately before
 * it. The page prints both date ranges rather than just "előző időszak", so the
 * reader never has to guess what the percentage is against.
 */
final class ReportRange
{
    public const PRESETS = ['this_month', 'last_month', 'last_30_days', 'this_year', 'custom'];

    /** Longest range the live queries will serve; beyond this the report needs a daily aggregate. */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $preset,
        /** Local start of the first day. */
        public readonly Carbon $start,
        /** Local end of the last day (inclusive). */
        public readonly Carbon $end,
        public readonly string $timezone,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  validated ReportFilterRequest input
     */
    public static function fromFilters(array $filters, string $timezone, ?Carbon $now = null): self
    {
        $localNow = ($now ?? Carbon::now())->copy()->setTimezone($timezone);
        $preset = is_string($filters['preset'] ?? null) && in_array($filters['preset'], self::PRESETS, true)
            ? $filters['preset']
            : 'this_month';

        [$start, $end] = match ($preset) {
            'last_month' => [
                $localNow->copy()->subMonthNoOverflow()->startOfMonth(),
                $localNow->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            // Today inclusive, so "30 days" is today plus the 29 before it.
            'last_30_days' => [
                $localNow->copy()->startOfDay()->subDays(29),
                $localNow->copy()->endOfDay(),
            ],
            'this_year' => [
                $localNow->copy()->startOfYear(),
                $localNow->copy()->endOfDay(),
            ],
            'custom' => self::customBounds($filters, $timezone, $localNow),
            default => [
                $localNow->copy()->startOfMonth(),
                $localNow->copy()->endOfDay(),
            ],
        };

        return new self($preset, $start, $end, $timezone);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function customBounds(array $filters, string $timezone, Carbon $localNow): array
    {
        $from = is_string($filters['from'] ?? null)
            ? Carbon::parse($filters['from'], $timezone)->startOfDay()
            : $localNow->copy()->startOfMonth();
        $to = is_string($filters['to'] ?? null)
            ? Carbon::parse($filters['to'], $timezone)->endOfDay()
            : $localNow->copy()->endOfDay();

        // A reversed range is a slip, not an error worth a 422 — read it as intended.
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /** Number of local days covered, inclusive. */
    public function dayCount(): int
    {
        return (int) $this->start->diffInDays($this->end->copy()->startOfDay()) + 1;
    }

    /**
     * The range this one is compared against.
     *
     * `subMonthNoOverflow` is what makes the month shift honest at the month end:
     * July 31 goes to June 30 rather than overflowing into July 1, so "last month"
     * lands on the whole previous calendar month.
     */
    public function previous(): self
    {
        [$start, $end] = match ($this->preset) {
            'this_month', 'last_month' => [
                $this->start->copy()->subMonthNoOverflow(),
                $this->end->copy()->subMonthNoOverflow()->endOfDay(),
            ],
            'this_year' => [
                $this->start->copy()->subYearNoOverflow(),
                $this->end->copy()->subYearNoOverflow()->endOfDay(),
            ],
            default => [
                $this->start->copy()->subDays($this->dayCount()),
                $this->start->copy()->subDay()->endOfDay(),
            ],
        };

        return new self($this->preset, $start, $end, $this->timezone);
    }

    public function startUtc(): Carbon
    {
        return $this->start->copy()->utc();
    }

    /**
     * The instant the range stops, exclusive: the next local midnight.
     *
     * Deliberately exclusive rather than `endOfDay`, whose 23:59:59.999999 loses a
     * minute in any duration arithmetic — a booking running to midnight would be
     * reported as 119 minutes instead of 120.
     */
    public function endExclusiveUtc(): Carbon
    {
        return $this->end->copy()->startOfDay()->addDay()->utc();
    }

    /**
     * Local start-of-day Carbons for every day in the range, in order. Iterating by
     * calendar day (not by adding 24h) keeps the DST days whole.
     *
     * @return list<Carbon>
     */
    public function days(): array
    {
        $days = [];
        for ($day = $this->start->copy(); $day->lessThanOrEqualTo($this->end); $day->addDay()) {
            $days[] = $day->copy();
        }

        return $days;
    }
}
