<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Domain\ProvisionTenantDomain;
use App\Enums\DomainProvisioningStatus;
use App\Models\Scopes\TenantScope;
use App\Models\TenantDomain;
use App\Services\Domain\CustomHostnameProvisioner;
use Illuminate\Console\Command;

/**
 * Moves custom domains from `pending` to `active` once the edge has finished
 * issuing their certificate (SLO-135).
 *
 * Certificate issuance is asynchronous and can take minutes while the tenant's
 * DNS propagates, and the provider does not call us back — so the state has to
 * be polled. Without this a domain that went live would keep telling the tenant
 * it is still waiting.
 *
 * Failed rows are retried too: a refusal is often a transient provider error or
 * DNS that had not propagated at the moment we first asked.
 */
class RefreshCustomHostnameCertificates extends Command
{
    protected $signature = 'domains:refresh-certificates {--limit=50 : Maximum domains to poll in one run}';

    protected $description = 'Poll the edge for custom domains whose certificate is still pending';

    public function handle(CustomHostnameProvisioner $provisioner, ProvisionTenantDomain $provision): int
    {
        if (! $provisioner->isConfigured()) {
            $this->info('No custom hostname provider configured — nothing to poll.');

            return self::SUCCESS;
        }

        // Unscoped: this runs from cron with no tenant bound, across all tenants.
        $domains = TenantDomain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNotNull('verified_at')
            ->whereIn('provisioning_status', [
                DomainProvisioningStatus::Pending->value,
                DomainProvisioningStatus::Failed->value,
            ])
            ->orderBy('provisioned_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $active = 0;

        foreach ($domains as $domain) {
            // Refresh where we already have the provider's id; a failed row may
            // never have been registered at all, so that one provisions afresh.
            if ($provision($domain, refresh: $domain->provider_hostname_id !== null)) {
                $active++;
            }
        }

        $this->info("Polled {$domains->count()} domain(s); {$active} now active.");

        return self::SUCCESS;
    }
}
