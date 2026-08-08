<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\Rbac\TenantTeam;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff member of the current tenant, as addressed by a route parameter
 * (SLO-142, tightened in SLO-146).
 *
 * {@see User} deliberately carries no tenant global scope — the login lookup and
 * the superadmin panel both need to see across tenants — so a `{user}` parameter
 * used to bind to *any* user and the tenant check happened in the controller.
 * That check ran **after** the Form Request, which made this the one endpoint in
 * the app where a foreign id did not answer 404 outright (it answered whatever
 * validation said). Moving the scope into the binding restores the rule the rest
 * of the surface follows: a foreign id is a 404 before anything else runs.
 *
 * Same subtype-with-its-own-binding pattern as {@see Customer}; both keep User's
 * table and morph class so nothing downstream can tell the difference.
 */
class TenantUser extends User
{
    protected $table = 'users';

    /**
     * spatie cannot infer a guard for this subtype (it is not a configured auth
     * provider model), so pin it to the users' `web` guard.
     */
    protected string $guard_name = 'web';

    /** Audit rows and polymorphic relations must record this as a plain User. */
    public function getMorphClass(): string
    {
        return (new User)->getMorphClass();
    }

    /**
     * Bind `{user}` to a staff member of the current tenant; anything else 404s.
     *
     * A customer of the same tenant is deliberately not bindable either: this
     * parameter serves the admin-access editor, and the customer roster belongs
     * to the customer module (SLO-84) with its own visibility rules.
     */
    public function resolveRouteBinding($value, $field = null): TenantUser
    {
        $tenant = app(TenantManager::class)->current();

        abort_if($tenant === null, 404);

        /** @var TenantUser $user */
        $user = TenantUser::query()
            ->where('tenant_id', $tenant->getKey())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();

        // The role check needs the tenant's spatie team context; without it the
        // relation resolves against no team and would come back empty.
        $isStaff = app(TenantTeam::class)->run($tenant, fn (): bool => $user->roles->contains(
            fn (Model $role): bool => Role::isStaffRoleName((string) $role->getAttribute('name')),
        ));

        abort_unless($isStaff, 404);

        return $user;
    }

    /**
     * @return Builder<TenantUser>
     */
    public static function staffOfCurrentTenant(): Builder
    {
        return TenantUser::query()->where('tenant_id', app(TenantManager::class)->id());
    }
}
