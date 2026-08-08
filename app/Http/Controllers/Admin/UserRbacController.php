<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Rbac\SyncUserRbac;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRbacRequest;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Per-user role assignment and permission overrides (SLO-142). Lives behind
 * `role.manage` next to the role editor, not in the staff module: who may hold
 * which grant is a `role.manage` decision, and a `staff.manage` holder must not
 * be able to promote themselves by editing a colleague through the back door.
 *
 * The target binds as {@see TenantUser}, which resolves only staff of the current
 * tenant — anything else 404s during route-model binding, before this method and
 * before the Form Request. Until SLO-146 the membership check lived here, which
 * meant a foreign id was answered by whatever validation said rather than by the
 * flat 404 the rest of the surface gives (docs/01).
 */
class UserRbacController extends Controller
{
    /**
     * `$tenant` is the {tenant} subdomain route parameter: non-class controller
     * arguments are filled positionally from the route, so the domain parameter
     * has to be declared before the model-bound one.
     */
    public function update(
        UpdateUserRbacRequest $request,
        string $tenant,
        TenantUser $user,
        TenantManager $tenants,
        SyncUserRbac $sync,
    ): RedirectResponse {
        $current = $this->tenant($tenants);

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
}
