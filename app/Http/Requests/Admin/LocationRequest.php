<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Http\Requests\Concerns\NormalizesPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for creating/updating a location (SLO-16). Shared by store+update
 * (identical fields). The tenant route group is already gated by
 * `can:location.manage`; authorize() re-asserts it for entry-point independence.
 */
class LocationRequest extends FormRequest
{
    use NormalizesPhone;

    protected function prepareForValidation(): void
    {
        $this->normalizePhone();
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::LocationManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'array'],
            'address.line' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.postal_code' => ['nullable', 'string', 'max:32'],
            'phone' => $this->phoneRules(32),
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'active' => ['boolean'],
        ];
    }
}
