<?php

namespace App\Policies;

use App\Models\User;

/**
 * The system email texts are the superadmin's alone (SLO-246): one edit
 * reaches every tenant's customers.
 */
class PlatformMailTextPolicy
{
    public function manage(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
