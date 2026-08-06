<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Models\Tenant;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runs a callback with spatie's team context pinned to a tenant (SLO-142).
 *
 * Every role and permission row is scoped to a team (team = tenant_id, named
 * `tenant_id` here rather than spatie's default `team_id`), and spatie reads
 * that team from ambient process state. The RBAC writes must not depend on
 * whatever the request chain happened to leave there: a queued job, an artisan
 * command or a superadmin acting on a tenant all arrive with a different — or
 * absent — team id, and an unscoped write lands on the wrong tenant's role or
 * silently on none.
 *
 * The previous id is always restored, including when the callback throws, so a
 * failed write cannot leak its team context into the rest of the request.
 */
final class TenantTeam
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenant->getKey());

        try {
            return $callback();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Drop the permission map spatie memoizes per process and caches in the
     * store. Every RBAC write ends with this: without it the change would only
     * take effect on the next cache expiry, and "applies immediately" would be a
     * coin flip.
     */
    public function flush(): void
    {
        $this->registrar->forgetCachedPermissions();
    }

    /** The `roles` table's team column, read from the registrar, never hardcoded. */
    public function key(): string
    {
        return (string) $this->registrar->teamsKey;
    }
}
