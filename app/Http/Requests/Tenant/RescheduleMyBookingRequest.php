<?php

namespace App\Http\Requests\Tenant;

use App\Enums\BookingMode;
use App\Models\Booking;
use App\Services\Booking\AvailabilityService;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a customer rescheduling their own booking from the members area
 * (SLO-97). Like {@see CancelMyBookingRequest}, authorisation is the ownership 404
 * in the controller (hidden existence), not a permission — a customer holds no
 * booking permission codes (SLO-86); `ensure.customer` already established they
 * are a customer of this tenant. The service and customer are taken from the route
 * booking, so only the new slot's fields are submitted; the controller re-derives
 * the authoritative slot from live availability and never trusts these verbatim.
 */
class RescheduleMyBookingRequest extends FormRequest
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
            'staff_id' => ['nullable', Rule::exists('staff', 'id')->where('tenant_id', $tenantId)],
            'room_id' => ['nullable', Rule::exists('rooms', 'id')->where('tenant_id', $tenantId)],
            // starts_at is only a lookup key: the controller re-derives the
            // authoritative slot (and its own end) from AvailabilityService. ends_at
            // is shape-validation only — ignored for persistence.
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // Free-range rental only (SLO-92): the caller-chosen length, bounds
            // enforced in withValidator.
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * A free-range resource_rental (duration_minutes null on the service) must carry
     * a caller-chosen duration within the service's min/max bounds (SLO-92, docs/04
     * §4). The service is resolved from the ROUTE booking (never a submitted id), so
     * the bounds are checked against the real service.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $booking = $this->route('booking');
            $service = $booking instanceof Booking ? $booking->service : null;

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
