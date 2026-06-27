<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a tenant's monthly billing period aggregate
 * (docs/10 §5.4). The period is a derived cache of the commission ledger;
 * once invoiced it is frozen and never recomputed (docs/10 §8.2).
 */
enum BillingPeriodStatus: string
{
    case Open = 'open';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    /**
     * Whether the period is still open for recomputation. Closed periods
     * (invoiced/paid/overdue/void) are accounting-stable and corrections go
     * to the current open period instead (docs/10 §8.2).
     */
    public function isRecomputable(): bool
    {
        return $this === self::Open;
    }
}
