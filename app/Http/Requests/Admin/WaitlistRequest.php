<?php

namespace App\Http\Requests\Admin;

use App\Actions\Waitlist\JoinWaitlist;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature as Pennant;

/**
 * Validation for adding a customer to an event waitlist (SLO-25). Every foreign id
 * is anchored to the current tenant. The waitlist feature gate is enforced here
 * (like ServiceRequest/EventRequest) — a tenant without feature_waitlist gets a
 * 422; the per-event/full/duplicate rules live in {@see JoinWaitlist}.
 */
class WaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::BookingCreate->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->id();

        return [
            'event_id' => ['required', Rule::exists('events', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['required', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'party_size' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! Pennant::active(Feature::Waitlist->value)) {
                $validator->errors()->add('waitlist', __('app.admin.waitlist.error.feature'));
            }
        });
    }
}
