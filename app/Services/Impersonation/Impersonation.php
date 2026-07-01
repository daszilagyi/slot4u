<?php

namespace App\Services\Impersonation;

use App\Models\Tenant;

/**
 * Superadmin tenant impersonation (SLO-79), backed by the shared subdomain
 * session (cookie domain `.{central}`, so it is readable on every tenant
 * subdomain — see SLO-75).
 *
 * The superadmin stays authenticated as themselves — impersonation only marks
 * *which* tenant context they are allowed into. That is deliberate: because the
 * authenticated user never changes, every audited action performed while
 * impersonating is recorded with the original superadmin as actor (the AC),
 * with no special-casing in AuditLogger. EnsureUserBelongsToTenant reads this
 * flag to let the impersonating superadmin through instead of redirecting them
 * back to the admin panel.
 */
class Impersonation
{
    private const KEY = 'impersonation';

    public function start(Tenant $tenant): void
    {
        session()->put(self::KEY, [
            'tenant_id' => $tenant->getKey(),
            'tenant_name' => $tenant->name,
        ]);
    }

    public function stop(): void
    {
        session()->forget(self::KEY);
    }

    public function tenantId(): ?int
    {
        $id = session(self::KEY.'.tenant_id');

        return $id === null ? null : (int) $id;
    }

    public function tenantName(): ?string
    {
        return session(self::KEY.'.tenant_name');
    }

    public function isActive(): bool
    {
        return $this->tenantId() !== null;
    }
}
