<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingMode;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\Event;
use App\Models\Service;
use App\Services\Event\WeeklyEventSeries;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature as Pennant;

/**
 * Validation for announcing/editing an event (SLO-20). The service must be an
 * event_based one; staff/room are optional but tenant-scoped; the occurrence must
 * not clash with another scheduled event for the same staff or room; and capacity
 * can never drop below the current registrations (docs/04 §3 edge case). Weekly
 * recurrence (create only) generates a series until an end date.
 */
class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ScheduleManage->value);
    }

    /**
     * The datetime-local inputs are naive wall-clock times in the tenant timezone;
     * convert them to UTC before validation so the stored instant, the conflict
     * check and display all agree on one basis (docs/01 §7).
     */
    protected function prepareForValidation(): void
    {
        $tenant = app(TenantManager::class)->current();
        $timezone = $tenant !== null ? $tenant->timezone : (string) config('app.timezone');

        foreach (['starts_at', 'ends_at'] as $key) {
            $value = $this->input($key);
            if (is_string($value) && $value !== '') {
                $this->merge([$key => Carbon::parse($value, $timezone)->utc()->toDateTimeString()]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->id();

        return [
            'service_id' => [
                'required',
                Rule::exists('services', 'id')->where('tenant_id', $tenantId),
            ],
            'staff_id' => [
                'nullable',
                Rule::exists('staff', 'id')->where('tenant_id', $tenantId),
            ],
            'room_id' => [
                'nullable',
                Rule::exists('rooms', 'id')->where('tenant_id', $tenantId),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'waitlist_enabled' => ['boolean'],

            // Weekly recurrence is only honoured on create (UpdateEvent uses the
            // this/following scope instead).
            'repeat_weekly' => ['boolean'],
            'repeat_until' => [
                Rule::requiredIf(fn () => $this->boolean('repeat_weekly')),
                'nullable', 'date', 'after:starts_at',
            ],
            // Edit/cancel/delete scope for a recurring occurrence.
            'scope' => ['nullable', Rule::in(['this', 'following'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateEventBasedService($validator);
            $this->validateWaitlistFeature($validator);
            $this->validateCapacityAboveBookings($validator);
            $this->validateRecurrenceSpan($validator);
            $this->validateSeriesFitsBetweenOccurrences($validator);
            $this->validateNoResourceClash($validator);
            $this->validateNoSeriesResourceClash($validator);
        });
    }

    /**
     * The event may only be announced for an event_based service.
     */
    private function validateEventBasedService(Validator $validator): void
    {
        $service = Service::find($this->integer('service_id'));
        if ($service !== null && $service->booking_mode !== BookingMode::EventBased) {
            $validator->errors()->add('service_id', __('app.admin.events.error.not_event_based'));
        }
    }

    /**
     * Waitlist can only be switched on when the tenant's feature is enabled
     * (docs/03), mirroring the service-level gate.
     */
    private function validateWaitlistFeature(Validator $validator): void
    {
        if ($this->boolean('waitlist_enabled') && ! Pennant::active(Feature::Waitlist->value)) {
            $validator->errors()->add('waitlist_enabled', __('app.admin.events.error.waitlist_feature'));
        }
    }

    /**
     * Capacity must not fall below the registrations already taken (docs/04 §3).
     */
    private function validateCapacityAboveBookings(Validator $validator): void
    {
        $event = $this->route('event');
        if ($event instanceof Event && $this->integer('capacity') < $event->booked_count) {
            $validator->errors()->add('capacity', __('app.admin.events.error.capacity_below_booked', [
                'count' => $event->booked_count,
            ]));
        }
    }

    /**
     * A recurring series is capped at 260 weekly occurrences (CreateEvent); reject
     * an until-date beyond that up front so the series is never silently truncated.
     */
    private function validateRecurrenceSpan(Validator $validator): void
    {
        if (! $this->boolean('repeat_weekly') || $this->input('repeat_until') === null) {
            return;
        }

        $maxUntil = Carbon::parse($this->input('starts_at'))->addWeeks(WeeklyEventSeries::MAX_OCCURRENCES - 1);
        if (Carbon::parse($this->input('repeat_until'))->gt($maxUntil)) {
            $validator->errors()->add('repeat_until', __('app.admin.events.error.repeat_too_far'));
        }
    }

    /**
     * A weekly series whose occurrence is a week or longer overlaps itself: week 2
     * starts before week 1 has finished, on the same staff and room. Rejected
     * here rather than by the clash check below, because that one compares the
     * series against events that already exist — nothing in it looks at the
     * series against itself (SLO-82).
     */
    private function validateSeriesFitsBetweenOccurrences(Validator $validator): void
    {
        if (! $this->boolean('repeat_weekly') || $this->route('event') instanceof Event) {
            return;
        }

        if ($this->date('starts_at')?->diffInDays($this->date('ends_at'), absolute: true) >= 7) {
            $validator->errors()->add('ends_at', __('app.admin.events.error.series_self_overlap'));
        }
    }

    /**
     * The staff/room must not already run another scheduled event that overlaps
     * this time window (the announce-time conflict check, docs/04).
     */
    private function validateNoResourceClash(Validator $validator): void
    {
        $staffId = $this->input('staff_id');
        $roomId = $this->input('room_id');
        if ($staffId === null && $roomId === null) {
            return;
        }

        $current = $this->route('event');

        $clashes = Event::query()
            ->where('status', 'scheduled')
            ->when($current instanceof Event, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->where('starts_at', '<', $this->date('ends_at'))
            ->where('ends_at', '>', $this->date('starts_at'))
            ->where(function ($q) use ($staffId, $roomId): void {
                if ($staffId !== null) {
                    $q->where('staff_id', $staffId);
                }
                if ($roomId !== null) {
                    $q->orWhere('room_id', $roomId);
                }
            })
            ->exists();

        if ($clashes) {
            $validator->errors()->add('starts_at', __('app.admin.events.error.resource_clash'));
        }
    }

    /**
     * The same clash rule, applied to the occurrences a weekly series would
     * generate rather than only to the one that was submitted (SLO-82).
     *
     * Until this existed, `validateNoResourceClash` cleared the first occurrence
     * and CreateEvent then wrote up to 259 more without anyone looking: a staff
     * member or a room could be booked onto two overlapping events on every later
     * week of the series, and nothing surfaced it until someone read the calendar.
     *
     * One query, not one per occurrence: the candidate events are loaded once for
     * the whole span of the series and the overlaps are decided in PHP. A series
     * may be 260 occurrences long, and 260 `exists()` calls behind a form submit
     * is the kind of thing the N+1 guard exists to stop.
     */
    private function validateNoSeriesResourceClash(Validator $validator): void
    {
        if (! $this->boolean('repeat_weekly') || $this->route('event') instanceof Event) {
            return;
        }

        $staffId = $this->input('staff_id');
        $roomId = $this->input('room_id');
        $startsAt = $this->date('starts_at');
        $endsAt = $this->date('ends_at');
        $repeatUntil = $this->input('repeat_until');

        if (($staffId === null && $roomId === null) || $startsAt === null || $endsAt === null || $repeatUntil === null) {
            return;
        }

        $timezone = $this->tenantTimezone();
        $until = Carbon::parse($repeatUntil, $timezone)->endOfDay();

        // Drop the first occurrence: validateNoResourceClash already reported it,
        // with the message that names a single time rather than a list.
        $occurrences = array_slice(
            WeeklyEventSeries::occurrences($startsAt, $endsAt, $until, $timezone),
            1
        );

        if ($occurrences === []) {
            return;
        }

        $spanStart = $occurrences[0]['starts_at'];
        $spanEnd = $occurrences[count($occurrences) - 1]['ends_at'];

        $existing = Event::query()
            ->where('status', 'scheduled')
            ->where('starts_at', '<', $spanEnd)
            ->where('ends_at', '>', $spanStart)
            ->where(function ($q) use ($staffId, $roomId): void {
                if ($staffId !== null) {
                    $q->where('staff_id', $staffId);
                }
                if ($roomId !== null) {
                    $q->orWhere('room_id', $roomId);
                }
            })
            ->get(['starts_at', 'ends_at']);

        if ($existing->isEmpty()) {
            return;
        }

        $clashing = [];

        foreach ($occurrences as $occurrence) {
            foreach ($existing as $event) {
                if ($event->starts_at < $occurrence['ends_at'] && $event->ends_at > $occurrence['starts_at']) {
                    $clashing[] = $occurrence['starts_at']->copy()->setTimezone($timezone)->format('Y. m. d. H:i');
                    break;
                }
            }
        }

        if ($clashing === []) {
            return;
        }

        $validator->errors()->add('repeat_until', __('app.admin.events.error.series_resource_clash', [
            'count' => count($clashing),
            'dates' => implode(', ', array_slice($clashing, 0, 5)),
        ]));
    }

    private function tenantTimezone(): string
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant !== null ? $tenant->timezone : (string) config('app.timezone');
    }
}
