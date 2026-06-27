<?php

declare(strict_types=1);

namespace App\Services\Commission;

/**
 * Immutable result of a period commission calculation.
 *
 * All amounts are integer minor units (docs/01 §6 — never float).
 *
 * @see docs/10-arazasi-modell-jutalek.md §6.1
 */
final readonly class CommissionResult
{
    public function __construct(
        /** Sum of all billable bookings' list prices (the tenant "turnover"). */
        public int $turnoverMinor,
        /** Turnover above the free threshold, i.e. the part commission is charged on. */
        public int $billableBaseMinor,
        /** Commission owed for the period after marginal calc and cap clamp. */
        public int $commissionMinor,
        /** True when the monthly cap bound the commission (raw commission >= cap). */
        public bool $capReached,
    ) {}
}
