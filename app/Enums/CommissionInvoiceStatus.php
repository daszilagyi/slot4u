<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a slot4u → tenant monthly commission invoice (docs/10 §5.5).
 * This is slot4u's own VAT-bearing SaaS revenue, collected by bank transfer;
 * non-payment drives dunning and eventual tenant suspension (docs/10 §6.6).
 */
enum CommissionInvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    /**
     * Whether the invoice is still awaiting payment (issued or overdue).
     */
    public function isOutstanding(): bool
    {
        return $this === self::Issued || $this === self::Overdue;
    }
}
