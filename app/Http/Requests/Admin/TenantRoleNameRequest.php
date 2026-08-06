<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role as RoleEnum;
use App\Services\Rbac\TenantTeam;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The name of a tenant's own role (SLO-142) — used both when creating one and
 * when renaming one.
 *
 * Two rules carry the weight:
 *
 * 1. **The built-in names are reserved.** `tenant-admin`, `manager`, `employee`,
 *    `customer` and `super-admin` are identified by name throughout the code
 *    (seeding, the members-area wall, the reset button), so a tenant-supplied
 *    row wearing one would be indistinguishable from the real thing.
 * 2. **Unique within the tenant.** Roles are scoped to the team column, so the
 *    uniqueness rule must be too — a global rule would let one tenant's naming
 *    choices reserve names for everybody else. The role being renamed is
 *    excluded so re-saving an unchanged name is not an error.
 */
class TenantRoleNameRequest extends FormRequest
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
        $teamKey = app(TenantTeam::class)->key();

        // On rename, the role's own row must not collide with itself. Expressed
        // as a where clause rather than `ignore()`, because the identifier here
        // is a free-text name and `ignore()` renders into the string form of the
        // rule, where a comma or a quote in the name would change its meaning.
        $current = $this->route('role');

        return [
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::notIn(RoleEnum::builtInNames()),
                Rule::unique('roles', 'name')->where(fn ($query) => $query
                    ->where('guard_name', 'web')
                    ->where($teamKey, $tenant?->getKey())
                    ->when(is_string($current), fn ($inner) => $inner->where('name', '!=', $current))),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.not_in' => __('admin.roles.name_reserved'),
            'name.unique' => __('admin.roles.name_taken'),
        ];
    }
}
