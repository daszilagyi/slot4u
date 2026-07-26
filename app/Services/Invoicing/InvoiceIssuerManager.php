<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Enums\InvoiceProvider;
use App\Services\Invoicing\Contracts\InvoiceIssuer;
use App\Services\Invoicing\Issuers\SandboxInvoiceIssuer;
use RuntimeException;

/**
 * Resolves the {@see InvoiceIssuer} behind a provider key (SLO-133). The platform
 * picks the driver (`config/invoicing.php`); the per-tenant credential lives in
 * the tenant's encrypted `invoicing` column and is passed in per request.
 *
 * Deliberately not final: this is the seam where a provider is swapped, and the
 * test suite substitutes a refusing issuer here to exercise the failure path.
 */
class InvoiceIssuerManager
{
    public function __construct(private readonly SandboxInvoiceIssuer $sandbox) {}

    /** The issuer new invoices are created with. */
    public function default(): InvoiceIssuer
    {
        $configured = InvoiceProvider::tryFrom((string) config('invoicing.default'));

        if ($configured === null) {
            throw new RuntimeException('Unknown invoicing provider configured: '.(string) config('invoicing.default'));
        }

        return $this->for($configured);
    }

    /**
     * The issuer for an existing invoice (a row can outlive the driver it was
     * issued with). Throws for a provider with no adapter yet.
     */
    public function for(InvoiceProvider $provider): InvoiceIssuer
    {
        return match ($provider) {
            InvoiceProvider::Sandbox => $this->sandbox,
            // The real Agent API client lands with SLO-134.
            InvoiceProvider::SzamlazzHu => throw new RuntimeException(
                'No invoicing adapter for provider: '.$provider->value
            ),
        };
    }
}
