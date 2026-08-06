<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role as RoleEnum;
use App\Services\Rbac\PermissionCatalog;
use App\Services\Rbac\TenantTeam;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * One staff user's roles and direct permission overrides (SLO-142).
 *
 * The allow-lists are built from the tenant's own rows, not from the enums: an
 * assignable role must exist inside this tenant's team (so another tenant's role
 * name is a 422, not a silent no-op), and a grantable code comes from
 * {@see PermissionCatalog} — which already excludes the admin-reserved codes and
 * anything a disabled feature makes meaningless. Granting `billing.edit`
 * directly to a user would otherwise walk straight around the role editor's
 * guardrail.
 *
 * At least one role is required. Zero roles is not "no permissions", it is a
 * user who fails the staff check and is bounced out of the admin panel on their
 * next request with a bare 403 — a lockout dressed up as a permission change.
 * Removing someone's access is the staff module's job (SLO-17).
 */
class UpdateUserRbacRequest extends FormRequest
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
            'roles' => ['present', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->assignableRoleNames())],
            // Present but empty = "no overrides on top of the roles", which is
            // the normal state; absent is not.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($grantable)],
        ];
    }

    /**
     * The roles this tenant may put on a staff user: every role in its own team
     * except `customer`, which is a members-area role — assigning it here would
     * not make the user a customer of anything, it would only muddy the wall
     * between the two areas (SLO-86).
     *
     * @return list<string>
     */
    private function assignableRoleNames(): array
    {
        $tenant = app(TenantManager::class)->current();

        if ($tenant === null) {
            return [];
        }

        /** @var list<string> */
        return RoleModel::query()
            ->where(app(TenantTeam::class)->key(), $tenant->getKey())
            ->where('name', '!=', RoleEnum::Customer->value)
            ->pluck('name')
            ->all();
    }
}
