<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Rbac\SyncRolePermissions;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Models\Tenant;
use App\Services\Rbac\PermissionCatalog;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * The tenant's own role editor (SLO-141, docs/03): which permissions the manager
 * and employee roles carry inside this tenant. Behind `role.manage` in
 * routes/tenant.php, i.e. tenant-admin only per the docs/03 matrix.
 *
 * Roles are spatie rows scoped to the tenant team (team = tenant_id), so every
 * lookup here pins the team column explicitly instead of trusting the ambient
 * registrar context — an unscoped `where('name', ...)` would match another
 * tenant's row of the same name. An unknown role name 404s; a role the policy
 * locks (tenant-admin, customer, the actor's own) 403s.
 */
class RoleController extends Controller
{
    /**
     * The roles table's team column. Read from the registrar rather than
     * hardcoded: config/permission.php names it `tenant_id` here, not spatie's
     * default `team_id`, and a silent mismatch would scope every query to
     * nothing (and so hand out an empty, harmless-looking page).
     */
    private function teamKey(): string
    {
        return (string) app(PermissionRegistrar::class)->teamsKey;
    }

    public function index(TenantManager $tenants, PermissionCatalog $catalog): Response
    {
        Gate::authorize('viewAny', RoleModel::class);

        $tenant = $this->tenant($tenants);
        $roles = $this->tenantRoles($tenant);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => array_map(
                fn (RoleModel $role): array => [
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                    // Why a row is read-only is the interesting part, so the page
                    // can say "this is your own role" instead of greying out.
                    'editable' => Gate::allows('update', $role),
                    'is_own' => $this->actorHasRole($role),
                    // Whether the grant still matches the seeded default, so the
                    // reset button only offers itself when it would do something.
                    'customized' => $this->isCustomized($role),
                ],
                $roles,
            ),
            'groups' => $catalog->groups($tenant),
        ]);
    }

    /**
     * `$tenant` is the {tenant} subdomain route parameter: non-class controller
     * arguments are filled positionally from the route, so the domain parameter
     * has to be declared before the path one (the MessageTemplateController
     * convention). The bound tenant still comes from the TenantManager.
     */
    public function update(
        UpdateRolePermissionsRequest $request,
        string $tenant,
        string $role,
        TenantManager $tenants,
        SyncRolePermissions $sync,
    ): RedirectResponse {
        $current = $this->tenant($tenants);
        $model = $this->findRole($current, $role);

        Gate::authorize('update', $model);

        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');

        $sync->update($current, $model, $permissions);

        return back()->with('success', __('admin.roles.updated'));
    }

    public function reset(
        string $tenant,
        string $role,
        TenantManager $tenants,
        SyncRolePermissions $sync,
    ): RedirectResponse {
        $current = $this->tenant($tenants);
        $model = $this->findRole($current, $role);

        Gate::authorize('update', $model);

        $sync->reset($current, $model);

        return back()->with('success', __('admin.roles.reset_done'));
    }

    private function tenant(TenantManager $tenants): Tenant
    {
        $tenant = $tenants->current();

        abort_if($tenant === null, 404);

        return $tenant;
    }

    /**
     * The tenant's four seeded roles in the documented hierarchy order, with
     * their grants eager-loaded (one query for the whole page, not one per row).
     *
     * @return list<RoleModel>
     */
    private function tenantRoles(Tenant $tenant): array
    {
        $names = array_map(
            static fn (RoleEnum $role): string => $role->value,
            RoleEnum::tenantRoles(),
        );

        $roles = RoleModel::query()
            ->where($this->teamKey(), $tenant->getKey())
            ->whereIn('name', $names)
            ->with('permissions:id,name')
            ->get()
            ->keyBy('name');

        return array_values(array_filter(array_map(
            static fn (string $name) => $roles->get($name),
            $names,
        )));
    }

    private function findRole(Tenant $tenant, string $name): RoleModel
    {
        return RoleModel::query()
            ->where($this->teamKey(), $tenant->getKey())
            ->where('name', $name)
            ->with('permissions:id,name')
            ->firstOrFail();
    }

    /**
     * Whether the acting user holds this role — the "you cannot edit your own"
     * guardrail, surfaced to the page so it can say *why* a row is locked.
     *
     * The team context is pinned to the role's own team before asking, because
     * spatie resolves a user's roles against the ambient team id and the tenant
     * chain may have moved on. Spatie memoizes the user's roles after the first
     * call, so the whole list costs one query, not one per role.
     */
    private function actorHasRole(RoleModel $role): bool
    {
        $user = request()->user();

        if ($user === null || $user->isSuperAdmin()) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($role->getAttribute($this->teamKey()));

        return $user->hasRole($role->name);
    }

    /**
     * Whether the role's grant has drifted from the seeded default (docs/03
     * matrix). A role that is not one of the built-in four has no default to
     * compare against, so it never counts as customized.
     */
    private function isCustomized(RoleModel $role): bool
    {
        $enum = RoleEnum::tryFrom($role->name);

        if ($enum === null) {
            return false;
        }

        $defaults = array_map(static fn ($permission): string => $permission->value, $enum->permissions());
        sort($defaults);

        $current = $role->permissions->pluck('name')->sort()->values()->all();

        return $current !== $defaults;
    }
}
