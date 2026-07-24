<?php

declare(strict_types=1);

namespace App\Services\Commission;

/**
 * One tenant's standing in a period, as the superadmin "top tenants by turnover"
 * table shows it (docs/10 §10). Money is integer minor units.
 */
final readonly class TopTenantStat
{
    public function __construct(
        public int $tenantId,
        public string $tenantName,
        public string $tenantSlug,
        public string $tenantStatus,
        public int $turnoverMinor,
        public int $commissionMinor,
        public bool $capReached,
    ) {}
}
