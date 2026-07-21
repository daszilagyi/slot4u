<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Superadmin manual settlement of a commission invoice (J8b, docs/10 §6.6). The
 * optional payment method is free text (e.g. "bank_transfer") recorded on the
 * invoice and in the audit trail; there is no external reconciliation yet.
 */
class MarkCommissionInvoicePaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin route group is already gated by auth + ensure.superadmin;
        // the controller additionally authorizes against CommissionInvoicePolicy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['nullable', 'string', 'max:100'],
        ];
    }
}
