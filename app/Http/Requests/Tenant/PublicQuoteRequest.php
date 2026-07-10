<?php

namespace App\Http\Requests\Tenant;

use App\Enums\Feature;
use App\Models\Service;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature as Pennant;

/**
 * Validation for a public (guest) quote request (SLO-102). The service's
 * customer-facing form (`settings.quote_fields`) is a list of labels, so the
 * answers arrive as a positional `fields` array — never as caller-chosen keys,
 * which would let a crafted POST write arbitrary json into `parameters`. The
 * controller pairs them back with the labels.
 *
 * The service is anchored to the current tenant and must be active. Anyone may
 * submit (public page); the quote_request feature is enforced by the route
 * middleware and re-checked here for a non-HTTP caller, mirroring
 * QuoteRequestStoreRequest.
 */
class PublicQuoteRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! Pennant::active(Feature::QuoteRequest->value)) {
                $validator->errors()->add('quote', __('app.tenant.book.quote_unavailable'));

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // The answers must line up with the service's own form definition —
            // a short or padded array means the page was rendered against a
            // different field set (or crafted by hand).
            $service = Service::query()->find($this->integer('service_id'));
            $answers = $this->input('fields', []);

            if ($service !== null && count(is_array($answers) ? $answers : []) !== count($service->quoteFields())) {
                $validator->errors()->add('quote', __('app.tenant.book.quote_fields_mismatch'));
            }
        });
    }
}
