<?php

namespace App\Http\Requests\Admin;

use App\Actions\Booking\RescheduleBooking;
use App\Enums\Permission;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validation for rescheduling a time-slot booking from the admin list (SLO-85) or by
 * dragging its card on the calendar (SLO-44). The service is kept from the original
 * booking; only the new slot (and optional resource) is submitted. datetime-local
 * inputs are tenant-local and converted to UTC before validation/storage
 * (docs/01 §7). The actual availability/conflict re-check happens in
 * {@see RescheduleBooking} → CreateBooking (a taken slot rolls back and surfaces as
 * a 422).
 *
 * `ends_at` is optional: a drag only picks a start, and the booking keeps the length
 * it already had. The controller derives it, so the duration survives a move across
 * a DST boundary — adding the wall-clock minutes on the client would have silently
 * shortened (or stretched) the appointment by an hour on the changeover day.
 */
class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::BookingEdit->value);
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'staff_id' => ['nullable', Rule::exists('staff', 'id')->where('tenant_id', $tenantId)],
            'room_id' => ['nullable', Rule::exists('rooms', 'id')->where('tenant_id', $tenantId)],
            // Opt out of the "your booking was moved" mail (docs/04 §2, SLO-44).
            // Absent = notify, so an older client keeps today's behaviour.
            'notify' => ['nullable', 'boolean'],
        ];
    }
}
