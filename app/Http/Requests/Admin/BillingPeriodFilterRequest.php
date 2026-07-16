<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The `?period=YYYY-MM` filter on the tenant billing dashboard and its CSV
 * export. Only the shape is validated here; whether the tenant actually *has*
 * that period is decided by the controller against its own period list, so an
 * unknown one is a 404 (not a 422) — a period key is an identifier, and probing
 * another tenant's must not read differently from probing a nonexistent one.
 *
 * Authorization is the route's `can:billing.view` gate plus the controller
 * policy check.
 */
class BillingPeriodFilterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
