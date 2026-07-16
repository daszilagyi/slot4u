<?php

namespace App\Policies;

use App\Models\User;

/**
 * Commission settings are the platform's pricing (docs/10 §5.1): only
 * super-admins (tenant_id = null) may read the history or publish a version. A
 * tenant sees the *effect* of the settings on its own billing page (J7), never
 * the configuration itself.
 *
 * Super-admins also pass via the Gate::before hook; this policy makes the rule
 * explicit and guards any future non-middleware entry point (e.g. API).
 */
class CommissionSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id === null;
    }

    /**
     * Versions are immutable, so publishing a new one is the only write — there
     * is deliberately no update or delete ability.
     */
    public function create(User $user): bool
    {
        return $user->tenant_id === null;
    }
}
