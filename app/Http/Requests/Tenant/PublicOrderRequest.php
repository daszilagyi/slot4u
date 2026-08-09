<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\Concerns\NormalizesPhone;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a public (guest) no_time_slot order (SLO-101). The mode carries
 * no start/end and no resource, so only the service and the guest's contact
 * details are validated; the controller re-checks the booking mode. The service
 * is anchored to the current tenant and must be active. Anyone may submit (public
 * page) — the approval/payment gates and the digital auto-complete live in
 * CreateBooking (docs/04 §1).
 */
class PublicOrderRequest extends FormRequest
{
    use NormalizesPhone;

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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => $this->phoneRules(),
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
