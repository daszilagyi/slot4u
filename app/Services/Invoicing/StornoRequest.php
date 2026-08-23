<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Settings\TenantInvoicingSettings;

/**
 * Everything an issuer needs to void one document (SLO-133, widened in SLO-143).
 *
 * ⚠️ This replaced an `Invoice` model in the {@see Contracts\InvoiceIssuer}
 * signature, which the interface's own docblock already argued against: an
 * adapter is a translation layer and should not know an Eloquent class. The
 * concrete reason it had to go is that slot4u's monthly commission invoice is a
 * `CommissionInvoice`, a different table entirely — with the model in the
 * signature there was no way to void one without either duplicating every
 * adapter or teaching them a second type.
 */
final class StornoRequest
{
    public function __construct(
        public readonly TenantInvoicingSettings $seller,
        /** The provider's own id for the document being voided. */
        public readonly ?string $providerRef,
        /** Its human-readable number, for providers (and humans) that quote it. */
        public readonly ?string $number,
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {}
}
