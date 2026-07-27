<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\Enums\DomainProvisioningStatus;
use App\Models\TenantDomain;
use App\Services\Domain\CustomHostnameProvisioner;
use App\Services\Domain\ProvisioningFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registers a verified custom domain with the edge that terminates TLS for it,
 * and records the outcome on the row (SLO-135).
 *
 * Owning a hostname and being able to serve it are separate facts, so a
 * refusal here NEVER touches `verified_at`: the tenant proved the domain is
 * theirs, and that stays true whether or not Cloudflare answered. The failure
 * is parked on the row with the provider's message so the admin sees it and
 * can retry — the same shape as a failed invoice issue (SLO-133).
 */
class ProvisionTenantDomain
{
    public function __construct(private readonly CustomHostnameProvisioner $provisioner) {}

    /**
     * @param  bool  $refresh  re-read an existing registration instead of creating one
     */
    public function __invoke(TenantDomain $domain, bool $refresh = false): bool
    {
        // Nothing to register in an environment with no provider, and nothing
        // to register for a domain whose ownership is not proven yet.
        if (! $this->provisioner->isConfigured() || ! $domain->isVerified()) {
            return false;
        }

        try {
            $hostname = $refresh
                ? $this->provisioner->refresh($domain)
                : $this->provisioner->provision($domain);
        } catch (ProvisioningFailed $e) {
            $this->recordFailure($domain, $e);

            return false;
        }

        $domain->provider_hostname_id = $hostname->id;
        $domain->provisioning_status = $hostname->isActive()
            ? DomainProvisioningStatus::Active
            : DomainProvisioningStatus::Pending;
        $domain->certificate_status = $hostname->certificateStatus;
        $domain->provisioning_error = null;
        $domain->provisioned_at ??= Date::now();
        $domain->saveQuietly();

        return $hostname->isActive();
    }

    /**
     * Written on every failed attempt, not just the last: a domain that cannot
     * be served has to be visible immediately, and a later successful attempt
     * simply overwrites this.
     */
    private function recordFailure(TenantDomain $domain, ProvisioningFailed $e): void
    {
        Log::warning('Custom hostname provisioning refused', [
            'tenant_domain_id' => $domain->getKey(),
            'domain' => $domain->domain,
            'error' => $e->getMessage(),
        ]);

        $domain->provisioning_status = DomainProvisioningStatus::Failed;
        $domain->provisioning_error = Str::limit($e->getMessage(), 490);
        $domain->saveQuietly();
    }
}
