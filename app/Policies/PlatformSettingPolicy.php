<?php

namespace App\Policies;

use App\Models\User;

/**
 * Platform settings — the look of every system email first (SLO-245) — belong
 * to slot4u alone. No permission grants them: a tenant admin holding every
 * permission still gets `false` here, and only the superadmin passes, through
 * the Gate::before hook. The superadmin host checks the same thing first; this
 * is the second lock, for an entry point that does not live on that host.
 */
class PlatformSettingPolicy
{
    public function manage(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
