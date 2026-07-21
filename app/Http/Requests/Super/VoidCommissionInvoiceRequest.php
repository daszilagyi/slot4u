<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Superadmin void of an outstanding commission invoice (J8b, docs/10 §10). The
 * optional reason is recorded in the audit trail so the void has a documented
 * cause (invoice raised in error, negotiated write-off, etc.).
 */
class VoidCommissionInvoiceRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
