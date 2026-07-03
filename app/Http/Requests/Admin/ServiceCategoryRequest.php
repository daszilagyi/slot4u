<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for creating/updating a service category (SLO-18). Shared by
 * store+update. Gated by `can:service.manage` at the route; authorize()
 * re-asserts it for entry-point independence.
 */
class ServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ServiceManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
