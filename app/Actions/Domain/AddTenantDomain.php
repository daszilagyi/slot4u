<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Tenancy\CustomDomainResolver;
use Illuminate\Support\Str;

/**
 * Claims a hostname for a tenant, unverified (SLO-42).
 *
 * The row exists before ownership is proven so the admin UI has somewhere to
 * show the TXT record the tenant must publish. Nothing is served on the host
 * until VerifyTenantDomain succeeds.
 */
class AddTenantDomain
{
    public function __construct(private readonly CustomDomainResolver $resolver) {}

    public function __invoke(Tenant $tenant, string $domain): TenantDomain
    {
        $row = new TenantDomain;
        $row->tenant_id = $tenant->getKey();
        $row->domain = $domain;
        $row->verification_token = Str::random(32);
        $row->save();

        // A host previously claimed and deleted may still be cached as pointing
        // at its old tenant — the new claim must not inherit that mapping.
        $this->resolver->forget($domain);

        return $row;
    }
}
