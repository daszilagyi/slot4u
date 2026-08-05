<?php

namespace App\Policies;

use App\Models\User;

/**
 * The tenant record itself is a platform-level object: it has no owning tenant,
 * so nothing inside a tenant may ever read across the whole set of them. Only
 * super-admins (tenant_id = null) do, and they already pass via the Gate::before
 * hook — this policy makes the rule explicit and testable rather than leaving it
 * to the route middleware alone.
 *
 * Tenant CRUD stays on the superadmin routes (SLO-77), which are gated by
 * ensure.superadmin; this policy covers the aggregate reads that cross every
 * tenant at once.
 */
class TenantPolicy
{
    /**
     * The platform-wide statistics view (SLO-138): the tenant lifecycle, growth
     * and churn series assembled from every tenant row, archived ones included.
     */
    public function viewGlobalStatistics(User $user): bool
    {
        return $user->tenant_id === null;
    }
}
