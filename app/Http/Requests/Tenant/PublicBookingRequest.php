<?php

namespace App\Http\Requests\Tenant;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a public (guest) booking submission (SLO-31). The slot's
 * start/end are the UTC instants produced by AvailabilityService (SLO-30) and are
 * stored as-is — no tenant-tz conversion (unlike the admin BookingRequest, whose
 * datetime-local inputs are tenant-local). Every foreign id is anchored to the
 * current tenant; the service must be active. Anyone may submit (public page), so
 * authorize() is open — race-safety + the approval/payment gates live in
 * CreateBooking.
 */
class PublicBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->id();

        return [
            'service_id' => ['required', Rule::exists('services', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            'staff_id' => ['nullable', Rule::exists('staff', 'id')->where('tenant_id', $tenantId)],
            'room_id' => ['nullable', Rule::exists('rooms', 'id')->where('tenant_id', $tenantId)],
            // starts_at is only a lookup key: store() re-derives the authoritative
            // slot (and its own end) from AvailabilityService and never persists
            // these verbatim. ends_at is shape-validation only — ignored for
            // persistence — so a malformed value cannot affect the outcome.
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
