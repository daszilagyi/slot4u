<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a customer cancelling their own booking from the members area
 * (SLO-33). Authorisation is the ownership 404 in the controller (hidden
 * existence), not a permission — a customer holds no booking permission codes
 * (SLO-86); the `ensure.customer` middleware already established they are a
 * customer of this tenant. The reason is optional and shown to the tenant.
 */
class CancelMyBookingRequest extends FormRequest
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
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
