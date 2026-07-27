<?php

declare(strict_types=1);

namespace App\Services\Domain;

use App\Models\TenantDomain;

/**
 * Registers a tenant's custom hostname with the edge that terminates TLS for
 * it (SLO-135). Cloudflare for SaaS today; the interface exists so that choice
 * stays reversible and so tests never reach the network.
 *
 * @see CloudflareCustomHostnameProvisioner
 * @see NullCustomHostnameProvisioner
 */
interface CustomHostnameProvisioner
{
    /**
     * Whether this environment is wired up to a provider at all. Dev, CI and a
     * fresh install are not, and must make no outbound calls.
     */
    public function isConfigured(): bool;

    /**
     * Register the hostname, or return the existing registration. Idempotent:
     * a hostname already known to the provider must not become a second one.
     *
     * @throws ProvisioningFailed
     */
    public function provision(TenantDomain $domain): ProvisionedHostname;

    /**
     * Re-read the provider's current view, so a certificate that has finished
     * validating since registration can be reflected back to the tenant.
     *
     * @throws ProvisioningFailed
     */
    public function refresh(TenantDomain $domain): ProvisionedHostname;

    /**
     * Release a hostname. Takes the provider's id rather than the model: the
     * row is usually already gone by the time this runs.
     *
     * @throws ProvisioningFailed
     */
    public function deprovision(string $providerHostnameId): void;
}
