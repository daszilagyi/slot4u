<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Holds the current tenant for the lifetime of a request (or job).
 *
 * Bound as a container *scoped* instance (not a static holder) so state never
 * leaks across requests, tests, or queue jobs — the queue worker resets scoped
 * instances between jobs. IdentifyTenant populates it; TenantScope and the
 * BelongsToTenant trait read from it.
 */
class TenantManager
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /**
     * Whether a tenant is currently bound (i.e. we are in tenant context).
     */
    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }
}
