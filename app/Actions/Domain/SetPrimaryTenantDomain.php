<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\Models\TenantDomain;
use App\Tenancy\CustomDomainResolver;
use Illuminate\Support\Facades\DB;

/**
 * Marks one verified domain as the tenant's canonical public host (SLO-42).
 *
 * The primary domain is what emails, canonical tags and the sitemap point at,
 * so it must be exactly one per tenant — the demotion and the promotion run in
 * a single transaction, and only a verified domain is eligible (promoting an
 * unverified one would send customers to a host that serves nothing).
 */
class SetPrimaryTenantDomain
{
    public function __construct(private readonly CustomDomainResolver $resolver) {}

    public function __invoke(TenantDomain $domain): void
    {
        if (! $domain->isVerified()) {
            return;
        }

        DB::transaction(function () use ($domain): void {
            TenantDomain::query()
                ->where('tenant_id', $domain->tenant_id)
                ->whereKeyNot($domain->getKey())
                ->update(['is_primary' => false]);

            $domain->is_primary = true;
            $domain->save();
        });

        $this->resolver->forget($domain->domain);
    }

    /**
     * Drops the tenant's primary host, falling back to the canonical subdomain.
     */
    public function clear(TenantDomain $domain): void
    {
        $domain->is_primary = false;
        $domain->save();

        $this->resolver->forget($domain->domain);
    }
}
