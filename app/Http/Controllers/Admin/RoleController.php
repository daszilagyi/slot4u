<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Rbac\CreateTenantRole;
use App\Actions\Rbac\DeleteTenantRole;
use App\Actions\Rbac\RenameTenantRole;
use App\Actions\Rbac\SyncRolePermissions;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantRoleNameRequest;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\PermissionCatalog;
use App\Services\Rbac\TenantTeam;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * The tenant's own role editor (SLO-141, SLO-142, docs/03): which permissions
 * each role carries inside this tenant, the tenant's own custom roles, and the
 * staff roster those roles apply to. Behind `role.manage` in routes/tenant.php,
 * i.e. tenant-admin only per the docs/03 matrix.
 *
 * Roles are spatie rows scoped to the tenant team (team = tenant_id), so every
 * lookup here pins the team column explicitly instead of trusting the ambient
 * registrar context — an unscoped `where('name', ...)` would match another
 * tenant's row of the same name. An unknown role name 404s; a role the policy
 * locks (tenant-admin, customer, the actor's own, a built-in on rename/delete)
 * 403s.
 */
class RoleController extends Controller
{
    public function __construct(private readonly TenantTeam $team) {}

    public function index(TenantManager $tenants, PermissionCatalog $catalog): Response
    {
        Gate::authorize('viewAny', RoleModel::class);

        $tenant = $this->tenant($tenants);
        $roles = $this->tenantRoles($tenant);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => array_map(fn (RoleModel $role): array => $this->roleData($role), $roles),
            'groups' => $catalog->groups($tenant),
            'users' => $this->staffData($tenant),
        ]);
    }

    /** Adds a role of the tenant's own (SLO-142). */
    public function store(
        TenantRoleNameRequest $request,
        TenantManager $tenants,
        CreateTenantRole $create,
    ): RedirectResponse {
        Gate::authorize('create', RoleModel::class);

        $tenant = $this->tenant($tenants);
        $create($tenant, (string) $request->validated('name'));

        return back()->with('success', __('admin.roles.created'));
    }

    /**
     * `$tenant` is the {tenant} subdomain route parameter: non-class controller
     * arguments are filled positionally from the route, so the domain parameter
     * has to be declared before the path one (the MessageTemplateController
     * convention).
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

    /** Renames a custom role (SLO-142); a built-in one is refused by the policy. */
    public function rename(
        TenantRoleNameRequest $request,
        string $tenant,
        string $role,
        TenantManager $tenants,
        RenameTenantRole $rename,
    ): RedirectResponse {
        $current = $this->tenant($tenants);
        $model = $this->findRole($current, $role);

        Gate::authorize('rename', $model);

        $rename($current, $model, (string) $request->validated('name'));

        return back()->with('success', __('admin.roles.renamed'));
    }

    /**
     * Deletes a custom role (SLO-142). A role users still hold comes back as a
     * validation error rather than a 403: it is a state the tenant can fix by
     * reassigning them, not a permission it lacks.
     */
    public function destroy(
        string $tenant,
        string $role,
        TenantManager $tenants,
        DeleteTenantRole $delete,
    ): RedirectResponse {
        $current = $this->tenant($tenants);
        $model = $this->findRole($current, $role);

        Gate::authorize('delete', $model);

        if (! $delete($current, $model)) {
            return back()->withErrors(['role' => __('admin.roles.delete_has_users')]);
        }

        return back()->with('success', __('admin.roles.deleted'));
    }

    public function reset(
        string $tenant,
        string $role,
        TenantManager $tenants,
        SyncRolePermissions $sync,
    ): RedirectResponse {
        $current = $this->tenant($tenants);
        $model = $this->findRole($current, $role);

        Gate::authorize('reset', $model);

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
     * Every role of the tenant: the seeded four in the documented hierarchy
     * order first, then the tenant's own by name. Grants and holder counts are
     * eager-loaded, so the page costs a fixed number of queries whatever the
     * tenant has created.
     *
     * @return list<RoleModel>
     */
    private function tenantRoles(Tenant $tenant): array
    {
        $roles = RoleModel::query()
            ->where($this->team->key(), $tenant->getKey())
            ->with('permissions:id,name')
            ->withCount('users')
            ->get()
            ->keyBy('name');

        $builtIn = array_values(array_filter(array_map(
            static fn (RoleEnum $role) => $roles->get($role->value),
            RoleEnum::tenantRoles(),
        )));

        $custom = $roles
            ->reject(static fn (RoleModel $role): bool => RoleEnum::isBuiltIn($role->name))
            ->sortBy('name')
            ->values()
            ->all();

        return [...$builtIn, ...$custom];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleData(RoleModel $role): array
    {
        return [
            'name' => $role->name,
            // The built-in names have translated labels and descriptions; a
            // custom role's name IS its label, so the page must know which is
            // which rather than looking up a lang key that would render as the
            // raw key on a miss.
            'built_in' => RoleEnum::isBuiltIn($role->name),
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            // Why a row is read-only is the interesting part, so the page can
            // say "this is your own role" instead of greying out.
            'editable' => Gate::allows('update', $role),
            'renamable' => Gate::allows('rename', $role),
            'deletable' => Gate::allows('delete', $role),
            'resettable' => Gate::allows('reset', $role),
            'is_own' => $this->actorHasRole($role),
            'holders' => (int) ($role->getAttribute('users_count') ?? 0),
            // Whether the grant still matches the seeded default, so the reset
            // button only offers itself when it would do something.
            'customized' => $this->isCustomized($role),
        ];
    }

    /**
     * The tenant's staff users with the grant each one holds (SLO-142).
     *
     * Staff only — a customer is a members-area account, and this editor is
     * about the admin panel. The filter runs in SQL rather than after loading:
     * a tenant's customers can outnumber its staff by orders of magnitude.
     *
     * @return list<array<string, mixed>>
     */
    private function staffData(Tenant $tenant): array
    {
        $users = $this->team->run($tenant, fn () => User::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereHas('roles', fn ($query) => $query->where('name', '!=', RoleEnum::Customer->value))
            ->with(['roles:id,name', 'permissions:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'tenant_id']));

        return $users->map(fn (User $user): array => [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles
                ->pluck('name')
                ->reject(static fn (string $name): bool => $name === RoleEnum::Customer->value)
                ->sort()->values()->all(),
            // Direct grants only. The codes a role already gives are not
            // repeated here, so the page can show inherited and individual
            // separately instead of one indistinguishable union.
            'permissions' => $user->permissions->pluck('name')->sort()->values()->all(),
            'editable' => Gate::allows('update', $user),
            'is_self' => request()->user()?->getKey() === $user->getKey(),
        ])->all();
    }

    private function findRole(Tenant $tenant, string $name): RoleModel
    {
        return RoleModel::query()
            ->where($this->team->key(), $tenant->getKey())
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

        app(PermissionRegistrar::class)
            ->setPermissionsTeamId($role->getAttribute($this->team->key()));

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
