<?php

namespace App\Policies;

use App\Models\User;

/**
 * A tenant's commission override is a platform pricing decision about that
 * tenant (docs/10 §5.2), not something the tenant administers: only super-admins
 * (tenant_id = null) may set or clear it. The tenant only ever sees the resolved
 * effect on its billing page (J7).
 *
 * Super-admins also pass via the Gate::before hook; this policy makes the rule
 * explicit and guards any future non-middleware entry point (e.g. API).
 */
class TenantCommissionOverridePolicy
{
    public function manage(User $user): bool
    {
        return $user->tenant_id === null;
    }
}
