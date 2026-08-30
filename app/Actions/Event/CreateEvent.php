<?php

namespace App\Actions\Event;

use App\Models\Event;
use App\Services\Event\WeeklyEventSeries;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Announces an event (SLO-20). With weekly recurrence it generates one occurrence
 * per week from the start until the end date, all sharing a series_id so the
 * series can be edited/cancelled together. tenant_id is stamped by
 * BelongsToTenant; booked_count/status default at the DB level.
 *
 * The occurrence list comes from {@see WeeklyEventSeries} rather than a loop of
 * its own, because EventRequest checks the very same list for resource clashes
 * (SLO-82). Two loops that had to stay in step would eventually not be.
 */
class CreateEvent
{
    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * @param  array<string, mixed>  $data
     * @return list<int> the created event ids
     */
    public function __invoke(array $data): array
    {
        $tenant = $this->tenants->current();
        $timezone = $tenant !== null ? $tenant->timezone : (string) config('app.timezone');

        // starts_at/ends_at arrive as UTC (EventRequest::prepareForValidation);
        // work in the tenant-local wall clock so weekly stepping is DST-correct.
        $startLocal = Carbon::parse($data['starts_at'], 'UTC')->setTimezone($timezone);
        $endLocal = Carbon::parse($data['ends_at'], 'UTC')->setTimezone($timezone);

        $base = [
            'service_id' => (int) $data['service_id'],
            'staff_id' => $data['staff_id'] ?? null,
            'room_id' => $data['room_id'] ?? null,
            'capacity' => (int) $data['capacity'],
            'waitlist_enabled' => (bool) ($data['waitlist_enabled'] ?? false),
        ];

        if (! (bool) ($data['repeat_weekly'] ?? false)) {
            return [$this->make($base, $startLocal, $endLocal, null, null)->id];
        }

        $until = Carbon::parse($data['repeat_until'], $timezone)->endOfDay();
        $seriesId = (string) Str::uuid();
        $rule = ['freq' => 'weekly', 'until' => $until->toDateString()];

        // The same list EventRequest checked for clashes — one generator, so a
        // conflict cleared at validation is the occurrence actually written.
        $occurrences = WeeklyEventSeries::occurrences($startLocal, $endLocal, $until, $timezone);

        return DB::transaction(function () use ($base, $occurrences, $seriesId, $rule): array {
            $ids = [];

            foreach ($occurrences as $occurrence) {
                $ids[] = $this->make($base, $occurrence['starts_at'], $occurrence['ends_at'], $seriesId, $rule)->id;
            }

            return $ids;
        });
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $rule
     */
    private function make(array $base, Carbon $starts, Carbon $ends, ?string $seriesId, ?array $rule): Event
    {
        $event = new Event;
        // Store the UTC instant (the model casts to datetime; DB is UTC).
        $event->fill([...$base, 'starts_at' => $starts->utc(), 'ends_at' => $ends->utc()]);
        // Guarded (not fillable): set explicitly from the action, never request input.
        $event->series_id = $seriesId;
        $event->recurrence_rule = $rule;
        $event->save();

        return $event;
    }
}
