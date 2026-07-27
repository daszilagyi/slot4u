<?php

namespace Tests\Fixtures;

use App\Models\TenantDomain;
use App\Services\Domain\CustomHostnameProvisioner;
use App\Services\Domain\ProvisionedHostname;
use App\Services\Domain\ProvisioningFailed;

/**
 * An in-memory edge provider for the custom hostname tests (SLO-135), so the
 * suite never reaches Cloudflare.
 */
class FakeCustomHostnameProvisioner implements CustomHostnameProvisioner
{
    /** @var list<string> */
    public array $provisioned = [];

    /** @var list<string> */
    public array $deprovisioned = [];

    public function __construct(
        public bool $configured = true,
        public string $certificateStatus = 'active',
        public ?string $failWith = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function provision(TenantDomain $domain): ProvisionedHostname
    {
        $this->guard();

        $this->provisioned[] = $domain->domain;

        return new ProvisionedHostname('cf-'.md5($domain->domain), $this->certificateStatus);
    }

    public function refresh(TenantDomain $domain): ProvisionedHostname
    {
        $this->guard();

        return new ProvisionedHostname(
            $domain->provider_hostname_id ?? 'cf-'.md5($domain->domain),
            $this->certificateStatus,
        );
    }

    public function deprovision(string $providerHostnameId): void
    {
        $this->guard();

        $this->deprovisioned[] = $providerHostnameId;
    }

    private function guard(): void
    {
        if ($this->failWith !== null) {
            throw ProvisioningFailed::fromApi($this->failWith);
        }
    }
}
