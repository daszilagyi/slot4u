<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TenantBillingPeriod;
use App\Models\User;

/**
 * The billing standing is a tenant-admin concern (docs/03 matrix: `billing.view`
 * is granted to Tenant Admin only — a manager runs the bookings, not the money).
 * Cross-tenant access is impossible: the BelongsToTenant global scope keeps every
 * read inside the current tenant. Super-admins pass via the Gate::before hook.
 *
 * Read-only by design — the aggregate is a cache the recompute owns (docs/10
 * §5.4); nothing a tenant does may write it directly.
 */
class TenantBillingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BillingView->value);
    }

    public function view(User $user, TenantBillingPeriod $period): bool
    {
        return $user->can(Permission::BillingView->value);
    }

    /**
     * The cross-tenant, platform-wide billing view (SLO-123 superadmin
     * statistics): only super-admins (tenant_id = null) aggregate every tenant's
     * periods at once. A tenant admin's `viewAny` above is scoped to its own
     * tenant by the global scope and never crosses this line.
     */
    public function viewGlobal(User $user): bool
    {
        return $user->tenant_id === null;
    }
}
