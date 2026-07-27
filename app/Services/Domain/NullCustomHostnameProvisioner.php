<?php

declare(strict_types=1);

namespace App\Services\Domain;

use App\Models\TenantDomain;

/**
 * The provisioner for environments with no edge provider configured — dev, CI,
 * a fresh install (SLO-135).
 *
 * It is not merely a silent no-op: `isConfigured()` returns false, and the
 * callers use that to skip provisioning entirely rather than record a bogus
 * success. A custom domain in such an environment stays verified-but-not-
 * provisioned, which is the truth.
 */
class NullCustomHostnameProvisioner implements CustomHostnameProvisioner
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function provision(TenantDomain $domain): ProvisionedHostname
    {
        throw new ProvisioningFailed('No custom hostname provider is configured.');
    }

    public function refresh(TenantDomain $domain): ProvisionedHostname
    {
        throw new ProvisioningFailed('No custom hostname provider is configured.');
    }

    public function deprovision(string $providerHostnameId): void
    {
        // Nothing was ever registered, so there is nothing to release.
    }
}
