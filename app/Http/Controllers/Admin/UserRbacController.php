<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Rbac\SyncUserRbac;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRbacRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\TenantTeam;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Per-user role assignment and permission overrides (SLO-142). Lives behind
 * `role.manage` next to the role editor, not in the staff module: who may hold
 * which grant is a `role.manage` decision, and a `staff.manage` holder must not
 * be able to promote themselves by editing a colleague through the back door.
 *
 * {@see User} carries no tenant global scope (a scope would break the superadmin
 * panel and the login lookup, see the model), so tenant membership is checked
 * here explicitly and answered with 404 — a cross-tenant probe must not confirm
 * that the row exists (docs/01).
 */
class UserRbacController extends Controller
{
    public function __construct(private readonly TenantTeam $team) {}

    /**
     * `$tenant` is the {tenant} subdomain route parameter: non-class controller
     * arguments are filled positionally from the route, so the domain parameter
     * has to be declared before the model-bound one.
     */
    public function update(
        UpdateUserRbacRequest $request,
        string $tenant,
        User $user,
        TenantManager $tenants,
        SyncUserRbac $sync,
    ): RedirectResponse {
        $current = $this->tenant($tenants);

        abort_unless($this->isTenantStaff($current, $user), 404);

        Gate::authorize('update', $user);

        /** @var list<string> $roles */
        $roles = $request->validated('roles');
        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');

        $sync($current, $user, $roles, $permissions);

        return back()->with('success', __('admin.roles.user_updated'));
    }

    private function tenant(TenantManager $tenants): Tenant
    {
        $tenant = $tenants->current();

        abort_if($tenant === null, 404);

        return $tenant;
    }

    /**
     * Whether the target is a staff user of this tenant. A customer of the same
     * tenant is deliberately not editable here either: this editor shapes admin
     * panel access, and the customer roster is the customer module's (SLO-84).
     */
    private function isTenantStaff(Tenant $tenant, User $user): bool
    {
        if ((int) $user->tenant_id !== $tenant->getKey()) {
            return false;
        }

        return $this->team->run($tenant, fn (): bool => $user->roles->contains(
            fn (Model $role): bool => RoleEnum::isStaffRoleName((string) $role->getAttribute('name')),
        ));
    }
}
