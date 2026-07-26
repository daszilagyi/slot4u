<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a customer invoice (docs/02 `invoices`, SLO-133).
 *
 * The row is created `pending` the moment a payment settles, so an invoicing
 * provider that is down leaves a visible, retryable obligation rather than a
 * silently uninvoiced payment.
 */
enum InvoiceStatus: string
{
    /** Queued for issuing; the provider has not answered yet. */
    case Pending = 'pending';

    /** Issued by the provider — it has a number and (usually) a PDF. */
    case Issued = 'issued';

    /** Issued and later voided by a storno invoice (a full refund). */
    case Storno = 'storno';

    /** The provider refused it; the admin can retry by hand. */
    case Failed = 'failed';

    /** Whether an admin may (re)try issuing this invoice. */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    /** Whether the invoice exists at the provider (so it can be stornoed). */
    public function isLive(): bool
    {
        return $this === self::Issued;
    }
}
