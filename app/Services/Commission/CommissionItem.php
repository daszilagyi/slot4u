<?php

declare(strict_types=1);

namespace App\Services\Commission;

/**
 * A single commission-bearing booking, reduced to the two numbers the
 * {@see CommissionCalculator} needs: its list price snapshot and the
 * commission rate that applied at the moment it became billable.
 *
 * Pure value object — no IO. The rate is snapshotted per item (docs/10 §2.4)
 * so a mid-month integration toggle never applies retroactively.
 *
 * @see docs/10-arazasi-modell-jutalek.md §2.3
 */
final readonly class CommissionItem
{
    public function __construct(
        /** List price snapshot in integer minor units (fillér/cent). */
        public int $amountMinor,
        /** Applicable commission rate in integer basis points (1% = 100 bps). */
        public int $rateBps,
    ) {}
}
