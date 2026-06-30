<?php

namespace App\Policies;

use App\Models\User;

/**
 * Audit logs are platform-level: only super-admins (tenant_id = null) may view
 * them. Super-admins also pass via the Gate::before hook; this policy makes the
 * rule explicit and guards any future non-middleware entry point (e.g. API).
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id === null;
    }
}
