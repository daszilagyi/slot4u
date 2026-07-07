<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating/updating a customer (SLO-84). Email uniqueness is
 * global (the MVP auth model is one user per email — docs/03), ignoring the
 * current customer on update.
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::CustomerEdit->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $customer = $this->route('customer');
        $ignoreId = $customer instanceof Customer ? $customer->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
