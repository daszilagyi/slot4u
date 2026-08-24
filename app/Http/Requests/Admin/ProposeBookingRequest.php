<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Http\Requests\Concerns\ScopesBookingCalendar;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validation for proposing an alternative time for a requested booking (SLO-26).
 * The slot mirrors BookingRequest: foreign ids are tenant-anchored and the
 * datetime-local inputs are tenant-local, converted to UTC before storage
 * (docs/01 §7). The reject/create split lives in ProposeAlternativeTime.
 */
class ProposeBookingRequest extends FormRequest
{
    use ScopesBookingCalendar;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::BookingApprove->value);
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
            'staff_id' => ['nullable', Rule::exists('staff', 'id')->where('tenant_id', $tenantId)],
            'room_id' => ['nullable', Rule::exists('rooms', 'id')->where('tenant_id', $tenantId)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * A restricted actor may not move a booking into someone else's calendar
     * (SLO-178). Optional: an absent staff_id keeps the resource the booking
     * already has, and that booking was ownership-checked before this ran.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateOwnCalendar($validator, required: false);
        });
    }
}
