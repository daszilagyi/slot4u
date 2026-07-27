<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\Models\TenantDomain;
use App\Tenancy\CustomDomainResolver;

/**
 * Releases a hostname (SLO-42). Hard delete, not a soft one: the unique index
 * on `domain` is what stops two tenants claiming the same host, so a released
 * name has to become claimable again. The cached mapping is dropped in the same
 * breath, otherwise the host would keep serving the old tenant until the TTL.
 */
class DeleteTenantDomain
{
    public function __construct(private readonly CustomDomainResolver $resolver) {}

    public function __invoke(TenantDomain $domain): void
    {
        $host = $domain->domain;

        $domain->delete();

        $this->resolver->forget($host);
    }
}
