<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingMode;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\Event;
use App\Models\Service;
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
            $this->validateNoResourceClash($validator);
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

        $maxUntil = Carbon::parse($this->input('starts_at'))->addWeeks(259);
        if (Carbon::parse($this->input('repeat_until'))->gt($maxUntil)) {
            $validator->errors()->add('repeat_until', __('app.admin.events.error.repeat_too_far'));
        }
    }

    /**
     * The staff/room must not already run another scheduled event that overlaps
     * this time window (the announce-time conflict check, docs/04). Recurrence
     * generation reuses this base check; per-occurrence clash refinement lands
     * with the M3 availability engine.
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
}
