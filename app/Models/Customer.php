<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\CustomerVisibility;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * A tenant's customer (docs/02, docs/03 §Customer, SLO-84): a {@see User} holding
 * the `customer` role. There is no separate customers table — customers are users,
 * so this thin subtype over the `users` table gives the admin customer management
 * a bindable, policy-backed type.
 *
 * No global scope is applied (one would be unsafe outside a tenant request — e.g.
 * queue jobs, the login lookup): tenant + role scoping is applied explicitly via
 * {@see self::tenantScoped()} and enforced on route binding, mirroring the
 * "users are scoped at the query/policy layer" convention on {@see User}.
 */
class Customer extends User
{
    protected $table = 'users';

    /**
     * spatie can't infer a guard for this subtype (it is not a configured auth
     * provider model), so pin it to the users' `web` guard — otherwise role
     * assignment/checks resolve an empty guard and mismatch the seeded roles.
     */
    protected string $guard_name = 'web';

    /**
     * Customers share the `users` table and authenticate through the `users`
     * auth provider — i.e. a logged-in customer is resolved as a {@see User}, not
     * a Customer. spatie stores role/permission rows keyed by the model's morph
     * class, so a role assigned to a Customer instance must use the SAME morph
     * type as User; otherwise `hasRole()` after login (and the `ensure.customer`
     * gate) would not see it (SLO-95). Align the morph class with User's.
     */
    public function getMorphClass(): string
    {
        return (new User)->getMorphClass();
    }

    /**
     * Base query for the current tenant's customers: users in this tenant holding
     * the customer role. The role filter runs against the current spatie team
     * (set by IdentifyTenant), so it never leaks another tenant's roster.
     *
     * @return Builder<Customer>
     */
    public static function tenantScoped(): Builder
    {
        return Customer::query()
            ->where('tenant_id', app(TenantManager::class)->id())
            ->whereHas('roles', fn (Builder $query) => $query->where('name', Role::Customer->value));
    }

    /**
     * Bind {customer} route params through the tenant+role scope so a foreign or
     * non-customer id 404s (cross-tenant probing must not leak — docs/01). The
     * actor's visibility is applied too, so an employee opening a colleague's
     * customer 404s (like a cross-tenant id) rather than 403 — no intra-tenant
     * existence leak. Permission (customer.view/edit) is gated at the route/policy.
     */
    public function resolveRouteBinding($value, $field = null): Customer
    {
        $query = self::tenantScoped()->where($field ?? $this->getRouteKeyName(), $value);

        $actor = Auth::user();
        if ($actor !== null) {
            CustomerVisibility::apply($query, $actor);
        }

        return $query->firstOrFail();
    }
}
