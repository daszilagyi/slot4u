<?php

namespace App\Http\Requests\Tenant;

use App\Enums\BookingMode;
use App\Http\Requests\Concerns\AcceptsLegalDocuments;
use App\Http\Requests\Concerns\NormalizesPhone;
use App\Models\Service;
use App\Services\Booking\AvailabilityService;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
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
    use AcceptsLegalDocuments, NormalizesPhone;

    protected function prepareForValidation(): void
    {
        $this->normalizePhone();
    }

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
            // Free-range rental only (SLO-92): the caller-chosen length, bounds
            // enforced in withValidator; store() re-validates the whole range.
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => $this->phoneRules(),
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + $this->legalRules();
    }

    /**
     * A free-range resource_rental (duration_minutes null on the service) must carry
     * a caller-chosen duration within the service's min/max bounds (SLO-92, docs/04
     * §4). Defense-in-depth over store()'s slot re-validation; a fixed-duration or
     * non-rental service ignores any submitted duration.
     */
    public function withValidator(Validator $validator): void
    {
        // The accepted documents must still be the ones in force (SLO-161).
        $validator->after(fn (Validator $validator) => $this->validateLegalDocumentsAreCurrent($validator));

        $validator->after(function (Validator $validator): void {
            $service = Service::query()->find($this->input('service_id')); // tenant-scoped by global scope
            if ($service === null
                || $service->booking_mode !== BookingMode::ResourceRental
                || $service->duration_minutes !== null) {
                return;
            }

            $duration = (int) $this->input('duration_minutes');
            if ($duration <= 0) {
                $validator->errors()->add('duration_minutes', __('validation.required', ['attribute' => __('app.booking.duration')]));

                return;
            }

            if (! app(AvailabilityService::class)->isDurationAllowed($service, $duration)) {
                $validator->errors()->add('duration_minutes', __('app.booking.error.duration_out_of_range'));
            }
        });
    }
}
