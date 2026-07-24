<?php

declare(strict_types=1);

namespace App\Services\Commission;

use Illuminate\Support\Carbon;

/**
 * An overdue commission invoice framed as suspension risk (docs/10 §6.6, §10):
 * how long until the dunning grace window runs out and the tenant is suspended.
 * Money is integer minor units.
 */
final readonly class OverdueInvoiceStat
{
    public function __construct(
        public int $invoiceId,
        public int $tenantId,
        public ?string $tenantName,
        public ?string $tenantSlug,
        public string $period,
        public int $totalGrossMinor,
        public string $currency,
        public Carbon $dueAt,
        public Carbon $suspendAt,
        /** Whole days from now until suspension; 0 once the grace window has passed. */
        public int $daysUntilSuspension,
    ) {}
}
