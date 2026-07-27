<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Domain\CustomHostnameProvisioner;
use App\Services\Domain\ProvisioningFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Releases a custom hostname at the edge after the tenant gave it up (SLO-135).
 *
 * Carries the provider's own id as a plain string rather than a model: by the
 * time this runs the `tenant_domains` row is deliberately gone (the unique
 * index has to free the hostname for whoever claims it next), so there is
 * nothing left to load.
 *
 * A registration left behind at Cloudflare is untidy but harmless — it serves
 * a hostname whose DNS no longer points at us — so exhausted retries are
 * logged, not escalated.
 */
class DeprovisionCustomHostname implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly string $providerHostnameId,
        private readonly string $domain,
    ) {
        $this->afterCommit();
    }

    public function handle(CustomHostnameProvisioner $provisioner): void
    {
        if (! $provisioner->isConfigured()) {
            return;
        }

        try {
            $provisioner->deprovision($this->providerHostnameId);
        } catch (ProvisioningFailed $e) {
            Log::warning('Custom hostname release refused', [
                'domain' => $this->domain,
                'provider_hostname_id' => $this->providerHostnameId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
