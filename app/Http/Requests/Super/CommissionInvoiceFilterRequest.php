<?php

namespace App\Http\Requests\Super;

use App\Enums\CommissionInvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the superadmin commission-invoice list (J8b): by status, billing
 * period (YYYY-MM) and tenant. All optional — an empty filter lists every
 * invoice across tenants.
 */
class CommissionInvoiceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin route group is already gated by auth + ensure.superadmin;
        // the controller additionally checks the CommissionInvoicePolicy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(CommissionInvoiceStatus::class)],
            // Period is the YYYY-MM key stored on the invoice (docs/10 §2.2).
            'period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'tenant_id' => ['nullable', 'integer'],
        ];
    }
}
