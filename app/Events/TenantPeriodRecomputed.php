<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant's monthly commission aggregate (tenant_billing_periods) was recomputed
 * from the ledger (docs/10 §6.2). Downstream consumers — the transparency
 * dashboard cache (J7) and statistics — react to this without re-reading the
 * ledger. Carries the identifiers only; the fresh figures live on the row.
 */
class TenantPeriodRecomputed
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $period,
    ) {}
}
