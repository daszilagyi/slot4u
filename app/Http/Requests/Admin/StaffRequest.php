<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\Staff;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating/updating a staff member (SLO-17). Shared by
 * store+update. The optional `email` field triggers the employee invitation
 * flow (a new login user). The tenant route group is already gated by
 * `can:staff.manage`; authorize() re-asserts it for entry-point independence.
 */
class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::StaffManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staff = $this->route('staff');
        $linkedUserId = $staff instanceof Staff ? $staff->user_id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'url', 'max:2048'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'active' => ['boolean'],
            // Optional invitation address; ignore the staff's own linked user so
            // re-saving a linked record never trips the unique rule.
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($linkedUserId),
            ],
            // Locations this staff works at (SLO-51), constrained to the tenant.
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => [
                'integer',
                Rule::exists('locations', 'id')->where('tenant_id', app(TenantManager::class)->id()),
            ],
        ];
    }
}
