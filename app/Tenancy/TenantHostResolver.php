<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Resolves the tenant that owns a request host, WITHOUT relying on the
 * `identify.tenant` route middleware. The Fortify auth routes (login/register)
 * are registered domain-less on every host, so they never carry the `{tenant}`
 * route parameter — yet on `{slug}.{central}` the registration flow must know it
 * is a customer sign-up for that tenant (SLO-95), not a new-tenant sign-up.
 *
 * Returns null for the central/admin domain, a reserved label, a multi-label
 * subdomain, or an unknown/archived slug — i.e. anywhere a tenant customer
 * registration does not apply.
 */
class TenantHostResolver
{
    public function resolve(string $host): ?Tenant
    {
        $suffix = '.'.config('tenancy.central_domain');

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        // Only a single subdomain label maps to a tenant (no `a.b.central`).
        if ($label === '' || str_contains($label, '.') || $this->isReserved($label)) {
            return null;
        }

        // Soft-deleted (archived) tenants are excluded by the default query.
        return Tenant::query()->where('slug', $label)->first();
    }

    private function isReserved(string $label): bool
    {
        $reserved = array_merge(
            (array) config('tenancy.reserved_subdomains', []),
            [config('tenancy.admin_subdomain')],
        );

        return in_array($label, $reserved, true);
    }
}
