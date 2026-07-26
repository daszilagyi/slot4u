<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Settings\TenantInvoicingSettings;

/**
 * Everything an issuer needs to produce one invoice (SLO-133), assembled by the
 * domain layer so no provider adapter has to reach into models of its own.
 */
final class InvoiceRequest
{
    public function __construct(
        public readonly TenantInvoicingSettings $seller,
        public readonly string $buyerName,
        public readonly ?string $buyerEmail,
        public readonly string $itemName,
        public readonly int $amountMinor,
        public readonly string $currency,
        /** The tenant-local calendar day the invoice is dated on. */
        public readonly string $issueDate,
    ) {}
}
