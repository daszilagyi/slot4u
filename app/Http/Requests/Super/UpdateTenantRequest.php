<?php

namespace App\Http\Requests\Super;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin route group is already gated by auth + ensure.superadmin.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->route('tenant');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'lowercase', 'min:3', 'max:63',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique(Tenant::class, 'slug')->ignore($tenant->getKey()),
                Rule::notIn($this->reservedSlugs()),
            ],
            'timezone' => ['required', 'timezone:all'],
            'locale' => ['required', 'string', Rule::in(['hu', 'en'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.not_in' => __('validation.custom.slug.reserved'),
            'slug.regex' => __('validation.custom.slug.regex'),
        ];
    }

    /**
     * @return list<string>
     */
    private function reservedSlugs(): array
    {
        return array_merge(
            (array) config('tenancy.reserved_subdomains', []),
            [config('tenancy.admin_subdomain')],
        );
    }
}
