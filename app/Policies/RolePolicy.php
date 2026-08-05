<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Who may reshape a tenant's roles (SLO-141, docs/03 `role.manage` = Tenant
 * Admin). Registered explicitly in AppServiceProvider — the model is spatie's,
 * so Laravel's policy auto-discovery cannot find it by naming convention.
 *
 * The guardrails live here rather than in the controller because they are
 * authorization decisions, and a 403 with a reason is the honest answer to a
 * direct POST at a role the editor renders locked. Super-admins pass via the
 * Gate::before hook — an impersonating superadmin is deliberately not bound by
 * the "not your own role" rule, since they hold no tenant role at all.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RoleManage->value);
    }

    /**
     * Editing a role's grant. Three roles are off limits whatever the actor's
     * permissions say:
     *
     * - **tenant-admin**: full authority is what the role *is*; a tenant-admin
     *   role with holes would leave the tenant unable to undo its own change.
     * - **customer**: it deliberately holds no admin-panel codes (docs/03,
     *   SLO-86). The customer's "own bookings, own profile" scope runs on
     *   ownership policies in the members area, so a code granted here would
     *   widen the admin panel, not the members area — the opposite of intent.
     * - **the actor's own role**: the concrete, enforceable form of "you cannot
     *   take away your own rights". Someone else with role.manage can still edit
     *   it, so a tenant is never locked out of its own configuration.
     */
    public function update(User $user, RoleModel $role): bool
    {
        if (! $user->can(Permission::RoleManage->value)) {
            return false;
        }

        if (in_array($role->name, [RoleEnum::TenantAdmin->value, RoleEnum::Customer->value], true)) {
            return false;
        }

        return ! $user->hasRole($role->name);
    }
}
