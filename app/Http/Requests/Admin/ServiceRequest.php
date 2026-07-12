<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingMode;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature as Pennant;

/**
 * Validation for creating/updating a service (SLO-18) — the most involved
 * master-data form. Rules are mode-dependent (e.g. duration_based requires a
 * duration, event_based a capacity) and feature-gated (waitlist / approval /
 * online-payment / quote_request only when the tenant's feature is enabled).
 *
 * Category/staff/room references are constrained to the current tenant so a
 * forged foreign id cannot slip through validation before the scope would 404.
 */
class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ServiceManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->id();
        $mode = BookingMode::tryFrom((string) $this->input('booking_mode'));

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => [
                'nullable',
                Rule::exists('service_categories', 'id')->where('tenant_id', $tenantId),
            ],
            'booking_mode' => ['required', Rule::enum(BookingMode::class)],

            'duration_minutes' => [
                Rule::requiredIf(fn () => $mode?->requiresDuration() === true),
                'nullable', 'integer', 'min:1', 'max:1440',
            ],
            'buffer_before_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'buffer_after_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'capacity' => [
                Rule::requiredIf(fn () => $mode?->requiresCapacity() === true),
                'nullable', 'integer', 'min:1', 'max:100000',
            ],

            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],

            'requires_staff' => ['boolean'],
            'requires_room' => ['boolean'],
            'requires_approval' => ['boolean'],
            'waitlist_enabled' => ['boolean'],
            'online_payment_required' => ['boolean'],
            'active' => ['boolean'],

            'settings' => ['nullable', 'array'],
            'settings.fulfillment_type' => ['nullable', 'string', Rule::in(['digital', 'manual', 'downloadable'])],
            // http(s) only — the value is rendered as an <a href> on the public
            // confirmation page, so a javascript:/data: scheme must never pass.
            'settings.content_url' => ['nullable', 'string', 'url:http,https', 'max:2000'],
            'settings.min_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'settings.max_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'settings.deposit_minor' => ['nullable', 'integer', 'min:0'],
            'settings.quote_fields' => ['nullable', 'array'],
            // The labels are the keys of the public form's answers (`parameters`,
            // SLO-102), so a duplicate would silently overwrite an answer.
            'settings.quote_fields.*' => ['required', 'string', 'max:255', 'distinct'],

            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => [
                'integer',
                Rule::exists('staff', 'id')->where('tenant_id', $tenantId),
            ],
            'room_ids' => ['nullable', 'array'],
            'room_ids.*' => [
                'integer',
                Rule::exists('rooms', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * Feature-dependent constraints (docs/03): a capability can only be switched
     * on for a service when the tenant's feature flag is enabled.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mode = BookingMode::tryFrom((string) $this->input('booking_mode'));

            // quote_request mode itself is gated by feature_quote_request.
            $required = $mode?->requiredFeature();
            if ($required !== null && ! Pennant::active($required->value)) {
                $validator->errors()->add('booking_mode', __('app.admin.services.error.feature_off'));
            }

            // waitlist only applies to event_based, and only with the feature.
            if ($this->boolean('waitlist_enabled')) {
                if ($mode?->supportsWaitlist() !== true) {
                    $validator->errors()->add('waitlist_enabled', __('app.admin.services.error.waitlist_mode'));
                } elseif (! Pennant::active(Feature::Waitlist->value)) {
                    $validator->errors()->add('waitlist_enabled', __('app.admin.services.error.feature_off'));
                }
            }

            if ($this->boolean('requires_approval') && ! Pennant::active(Feature::ApprovalFlow->value)) {
                $validator->errors()->add('requires_approval', __('app.admin.services.error.feature_off'));
            }

            if ($this->boolean('online_payment_required') && ! Pennant::active(Feature::OnlinePayment->value)) {
                $validator->errors()->add('online_payment_required', __('app.admin.services.error.feature_off'));
            }
        });
    }
}
