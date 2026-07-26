<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

/**
 * What an issuer hands back for a successfully issued (or stornoed) invoice
 * (SLO-133): the provider's own number and reference, plus the PDF bytes when the
 * provider returns one. A provider that only sends a number leaves `pdf` null and
 * the invoice is still valid — the download simply 404s until a document exists.
 */
final class IssuedInvoice
{
    public function __construct(
        public readonly string $number,
        public readonly ?string $providerRef = null,
        public readonly ?string $pdf = null,
    ) {}
}
