<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Domain\ProvisionTenantDomain;
use App\Models\Scopes\TenantScope;
use App\Models\TenantDomain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Registers a freshly verified custom domain at the edge (SLO-135).
 *
 * Queued because it is an outbound API call on the tail of an interactive
 * request — the admin should get the verification result immediately, not wait
 * on Cloudflare — and `afterCommit` so nothing is registered for a
 * verification that rolled back.
 *
 * Takes the id, not the model, so a retry always reads the current row. The
 * action itself records failures, so the job stays thin.
 */
class ProvisionCustomHostname implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A provider blip is normal; three attempts with a growing pause. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $tenantDomainId,
        private readonly bool $refresh = false,
    ) {
        $this->afterCommit();
    }

    public function handle(ProvisionTenantDomain $provision): void
    {
        // Unscoped: the queue worker has no tenant bound.
        $domain = TenantDomain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->find($this->tenantDomainId);

        if (! $domain instanceof TenantDomain) {
            return;
        }

        $provision($domain, $this->refresh);
    }
}
