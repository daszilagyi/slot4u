<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingMode;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\Service;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature as Pennant;

/**
 * Validation for creating a quote request (SLO-27). Every foreign id is anchored
 * to the current tenant. The quote_request feature gate is enforced here (like
 * ServiceRequest / WaitlistRequest) — a tenant without feature_quote_request gets
 * a 422 — and the service must actually be a quote_request-mode service.
 */
class QuoteRequestStoreRequest extends FormRequest
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
            'service_id' => ['required', Rule::exists('services', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Defence in depth: the store route already sits behind
            // ensure.feature:feature_quote_request (so the HTTP path 403s before
            // this runs), but a direct/other caller is still guarded here.
            if (! Pennant::active(Feature::QuoteRequest->value)) {
                $validator->errors()->add('quote_request', __('app.admin.quotes.error.feature'));

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $service = Service::find($this->integer('service_id'));
            if ($service !== null && $service->booking_mode !== BookingMode::QuoteRequest) {
                $validator->errors()->add('service_id', __('app.admin.quotes.error.wrong_mode'));
            }
        });
    }
}
