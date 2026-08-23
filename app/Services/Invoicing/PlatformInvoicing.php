<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Enums\InvoiceProvider;
use App\Services\Invoicing\Contracts\InvoiceIssuer;
use App\Settings\TenantInvoicingSettings;

/**
 * slot4u as a SELLER (SLO-143, docs/10 §15.1).
 *
 * Everywhere else in this namespace the tenant is the seller and slot4u is the
 * machinery. The monthly commission invoice runs the other way: slot4u sells a
 * service, the tenant buys it, and the document is slot4u's own VAT-bearing
 * revenue. So the credential comes from the platform's configuration and never
 * from a tenant row — billing a tenant with that tenant's own API key would be
 * wrong on its face, and would also let one tenant's broken configuration stop
 * slot4u from invoicing it.
 *
 * ⚠️ The seller details are carried in a {@see TenantInvoicingSettings}, whose
 * name is now half a lie. It is the shape every issuer already accepts — "who is
 * selling, and with what credential" — and renaming it would touch the tenant
 * invoicing flow, the Billingo adapter and their tests for no behavioural gain.
 * Recorded here rather than left for the next reader to wonder about.
 */
final class PlatformInvoicing
{
    public function __construct(private readonly InvoiceIssuerManager $issuers) {}

    /**
     * Who slot4u is on the invoice, and what it authenticates with.
     */
    public function seller(): TenantInvoicingSettings
    {
        /** @var array<string, mixed> $platform */
        $platform = (array) config('invoicing.platform', []);

        return new TenantInvoicingSettings(
            provider: $this->provider(),
            apiKey: self::str($platform, 'api_key'),
            sellerName: self::str($platform, 'seller_name'),
            sellerTaxNumber: self::str($platform, 'seller_tax_number'),
            sellerAddress: self::str($platform, 'seller_address'),
            vatKey: self::str($platform, 'vat_key') ?? TenantInvoicingSettings::DEFAULT_VAT_KEY,
            blockId: isset($platform['block_id']) && is_numeric($platform['block_id'])
                ? (int) $platform['block_id']
                : null,
        );
    }

    public function issuer(): InvoiceIssuer
    {
        return $this->issuers->for($this->provider());
    }

    /**
     * The provider slot4u invoices through.
     *
     * An unrecognised value falls back to the sandbox rather than throwing. This
     * runs inside the monthly close: a typo in the production env must not be
     * able to stop the commission invoice from existing — the debt is real
     * whether or not a document was produced, and a sandbox document that is
     * obviously a sandbox document is a louder signal than a failed job.
     */
    private function provider(): InvoiceProvider
    {
        return InvoiceProvider::tryFrom((string) config('invoicing.platform.provider'))
            ?? InvoiceProvider::Sandbox;
    }

    /**
     * Whether a real (non-sandbox) channel is configured — what the operator
     * checklist asks, and what the superadmin screen reports.
     */
    public function isLive(): bool
    {
        $seller = $this->seller();

        return $seller->provider !== InvoiceProvider::Sandbox
            && $seller->hasApiKey()
            && $seller->sellerName !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
