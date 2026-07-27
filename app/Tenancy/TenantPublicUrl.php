<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\Feature;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Feature\FeatureResolver;

/**
 * The host a tenant's public surface should be addressed by (SLO-42).
 *
 * Its verified primary custom domain when `feature_custom_domain` is on, and
 * the canonical `{slug}.{central}` subdomain otherwise. Every absolute link we
 * *generate* for a tenant — emails, canonical tags, share URLs — goes through
 * here, so switching a tenant onto its own domain (or losing the feature) moves
 * all of them at once instead of leaving half the product on the old host.
 *
 * Requests still arrive on either host; this is about what we hand out.
 */
class TenantPublicUrl
{
    /** @var array<int, string> */
    private array $hosts = [];

    public function __construct(private readonly FeatureResolver $features) {}

    public function host(Tenant $tenant): string
    {
        $id = (int) $tenant->getKey();

        if (isset($this->hosts[$id])) {
            return $this->hosts[$id];
        }

        return $this->hosts[$id] = $this->resolveHost($tenant);
    }

    /**
     * An absolute URL on the tenant's public host. Built from the tenant rather
     * than the current request, because most callers are queue jobs with no
     * request bound at all.
     */
    public function to(Tenant $tenant, string $path = '/'): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return sprintf('%s://%s%s', $scheme, $this->host($tenant), $path);
    }

    public function subdomain(Tenant $tenant): string
    {
        return $tenant->slug.'.'.config('tenancy.central_domain');
    }

    private function resolveHost(Tenant $tenant): string
    {
        if (! $this->features->enabled($tenant, Feature::CustomDomain)) {
            return $this->subdomain($tenant);
        }

        // Explicitly tenant-filtered, so the global scope would only ever be
        // redundant — or wrong, when a different tenant happens to be bound.
        $primary = TenantDomain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->getKey())
            ->where('is_primary', true)
            ->whereNotNull('verified_at')
            ->value('domain');

        return is_string($primary) ? $primary : $this->subdomain($tenant);
    }
}
