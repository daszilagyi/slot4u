<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingMode;
use App\Enums\Permission;
use App\Models\Service;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validation for an admin-created booking (SLO-24). Every foreign id is
 * constrained to the current tenant (the `exists` rule uses a raw query that does
 * NOT apply the tenant scope, so it is anchored explicitly). datetime-local inputs
 * are tenant-local and converted to UTC before validation/storage (docs/01 §7).
 * Per-mode requirements (slot vs event) are enforced in withValidator.
 */
class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::BookingCreate->value);
    }

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
            'service_id' => ['required', Rule::exists('services', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'staff_id' => ['nullable', Rule::exists('staff', 'id')->where('tenant_id', $tenantId)],
            'room_id' => ['nullable', Rule::exists('rooms', 'id')->where('tenant_id', $tenantId)],
            'event_id' => ['nullable', Rule::exists('events', 'id')->where('tenant_id', $tenantId)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $service = Service::find($this->integer('service_id'));
            if ($service === null) {
                return;
            }

            match ($service->booking_mode) {
                BookingMode::EventBased => $this->requireEvent($validator),
                BookingMode::DurationBased, BookingMode::ResourceRental => $this->requireSlot($validator, $service),
                // A quote_request service is booked only by accepting a quote
                // (docs/04 §6, SLO-27) — never directly here. no_time_slot falls
                // through to default (no slot/resource required).
                BookingMode::QuoteRequest => $validator->errors()->add('service_id', __('app.admin.bookings.error.quote_mode')),
                default => null,
            };
        });
    }

    private function requireEvent(Validator $validator): void
    {
        if ($this->input('event_id') === null) {
            $validator->errors()->add('event_id', __('app.admin.bookings.error.event_required'));
        }
    }

    private function requireSlot(Validator $validator, Service $service): void
    {
        if ($this->input('starts_at') === null) {
            $validator->errors()->add('starts_at', __('app.admin.bookings.error.slot_required'));
        } elseif ($this->input('ends_at') === null && $service->duration_minutes === null) {
            // The end may be left to the controller, which derives it from the
            // service duration in real elapsed time (SLO-136) — but a service with
            // no fixed duration (a variable-length rental, docs/04 §4) has nothing
            // to derive it from, so there the end has to be given.
            $validator->errors()->add('ends_at', __('app.admin.bookings.error.end_required'));
        }

        // resource_rental books a room; a staff-based service books a staff.
        $needsRoom = $service->booking_mode === BookingMode::ResourceRental;
        $missing = $needsRoom
            ? $this->input('room_id') === null
            : ($this->input('staff_id') === null && $this->input('room_id') === null);

        if ($missing) {
            $validator->errors()->add($needsRoom ? 'room_id' : 'staff_id', __('app.admin.bookings.error.resource_required'));
        }
    }
}
