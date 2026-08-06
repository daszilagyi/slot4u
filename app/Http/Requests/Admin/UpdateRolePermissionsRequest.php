<?php

namespace App\Http\Requests\Admin;

use App\Services\Rbac\PermissionCatalog;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The role editor's submission (SLO-141): the full set of permission codes the
 * role should end up with.
 *
 * The allow-list is {@see PermissionCatalog::grantableCodes()} — the tenant's
 * own catalog, not the whole Permission enum — so an admin-reserved code
 * (`billing.*`, `role.manage`) or a code whose feature is off is a 422 rather
 * than something the UI merely declined to render. Authorization of the *role*
 * is the RolePolicy's job in the controller; this only guards the payload.
 */
class UpdateRolePermissionsRequest extends FormRequest
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
        $tenant = app(TenantManager::class)->current();
        $grantable = $tenant !== null ? app(PermissionCatalog::class)->grantableCodes($tenant) : [];

        return [
            // Present but empty = a role with no permissions at all, which is a
            // legitimate thing to want; absent is not.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($grantable)],
        ];
    }
}
