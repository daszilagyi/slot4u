<?php

declare(strict_types=1);

namespace App\Services\Commission;

/**
 * One tenant's standing in a period, as the superadmin "top tenants by turnover"
 * table shows it (docs/10 §10). Money is integer minor units.
 *
 * `commissionMinor` is the gross accrual and `correctionMinor` the credits
 * carried into the period (<= 0, §8.2); `netMinor` is what the tenant is
 * actually billed — zero for a voided period.
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
        public int $correctionMinor,
        public int $netMinor,
        public bool $capReached,
    ) {}
}
