<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Scopes\TenantScope;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\Cache;

/**
 * Maps an incoming Host header to the verified custom domain that owns it
 * (SLO-42). Runs before routing, on every request, so it stays cheap: one
 * indexed equality lookup, memoised per request and cached across requests.
 *
 * Only *positive* results are cached. Caching misses would let any stranger
 * mint unbounded cache keys by varying the Host header, which on the shared
 * host (database cache store) means unbounded rows.
 */
class CustomDomainResolver
{
    /** @var array<string, TenantDomain|null> */
    private array $memo = [];

    /**
     * The verified custom domain serving this host, or null for the central
     * domain, an unknown host, or a host whose ownership is not proven yet.
     */
    public function resolve(string $host): ?TenantDomain
    {
        $host = DomainName::normalize($host);

        if ($host === null || DomainName::isCentral($host)) {
            return null;
        }

        if (array_key_exists($host, $this->memo)) {
            return $this->memo[$host];
        }

        $id = Cache::get($this->cacheKey($host));

        // Deliberately unscoped: this lookup is what decides *which* tenant the
        // request belongs to, so it cannot already be filtered by one. Nothing
        // tenant-owned is read here beyond the mapping itself.
        $query = fn () => TenantDomain::query()->withoutGlobalScope(TenantScope::class)->with('tenant');

        $domain = is_int($id)
            ? $query()->find($id)
            : $query()->where('domain', $host)->first();

        // A cached id can outlive its row (deleted domain) — fall back rather
        // than serving nothing, and never serve an unverified or orphaned row.
        if ($domain !== null && ($domain->domain !== $host || ! $domain->isVerified() || $domain->tenant === null)) {
            $domain = null;
        }

        if ($domain !== null) {
            Cache::put($this->cacheKey($host), $domain->getKey(), $this->ttl());
        }

        return $this->memo[$host] = $domain;
    }

    /**
     * Drop the cached mapping for a host. Called from every write that can
     * change whether — or for whom — a host resolves.
     */
    public function forget(string $host): void
    {
        $normalized = DomainName::normalize($host) ?? $host;

        Cache::forget($this->cacheKey($normalized));

        unset($this->memo[$normalized]);
    }

    private function cacheKey(string $host): string
    {
        return 'tenant_domain:'.$host;
    }

    private function ttl(): int
    {
        return max(1, (int) config('tenancy.resolution_ttl', 300));
    }
}
