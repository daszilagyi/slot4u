<?php

namespace App\Policies;

use App\Actions\Rbac\DeleteTenantRole;
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

    /** Adding a role of the tenant's own (SLO-142). */
    public function create(User $user): bool
    {
        return $user->can(Permission::RoleManage->value);
    }

    /**
     * Renaming a role. Custom roles only: the seeded names are load-bearing —
     * seeding, the members-area wall and the reset button all identify a role by
     * name — so a renamed `manager` would be a role the code no longer knows.
     */
    public function rename(User $user, RoleModel $role): bool
    {
        return ! RoleEnum::isBuiltIn($role->name) && $this->update($user, $role);
    }

    /**
     * Deleting a role. Custom only, for the same reason as {@see rename()}, and
     * never the actor's own (inherited from {@see update()}) — deleting the role
     * you hold is the fastest possible way to lock yourself out. Whether the role
     * still has holders is not an authorization question and is answered by
     * {@see DeleteTenantRole}.
     */
    public function delete(User $user, RoleModel $role): bool
    {
        return ! RoleEnum::isBuiltIn($role->name) && $this->update($user, $role);
    }

    /**
     * Restoring the seeded default. Built-in roles only — a custom role has no
     * documented default to restore, so the button does not exist for it and a
     * direct POST is refused rather than guessed at.
     */
    public function reset(User $user, RoleModel $role): bool
    {
        return RoleEnum::isBuiltIn($role->name) && $this->update($user, $role);
    }
}
