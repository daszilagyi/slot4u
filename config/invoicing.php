<?php

use App\Enums\InvoiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Default invoicing provider
    |--------------------------------------------------------------------------
    |
    | Which provider issues the tenant's customer invoices (SLO-133). Only
    | `sandbox` ships today — the built-in test issuer that makes the whole
    | issue → storno → download flow demoable without an external account. The
    | Számlázz.hu Agent API client (SLO-134) plugs into the same contract.
    |
    */

    'default' => env('INVOICING_PROVIDER', InvoiceProvider::Sandbox->value),

    /*
    |--------------------------------------------------------------------------
    | Document storage
    |--------------------------------------------------------------------------
    |
    | Invoice PDFs are customer documents: they live on a PRIVATE disk under a
    | per-tenant prefix and are only ever served through an authorised download
    | route — never a public URL.
    |
    */

    'disk' => env('INVOICING_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | slot4u's OWN invoicing channel (SLO-143, docs/10 §15.1)
    |--------------------------------------------------------------------------
    |
    | The monthly commission invoice slot4u issues TO a tenant. A different
    | direction of trade from everything above: there the tenant is the seller
    | and we are the machinery, here slot4u is the seller and the tenant is the
    | buyer. It therefore uses the platform's OWN provider account and never a
    | tenant's credential — reading a tenant's key to bill that same tenant would
    | be both wrong and, quietly, a way for a tenant's misconfiguration to stop
    | slot4u from invoicing.
    |
    | ⚠️ The provider defaults to `sandbox`, which mints a real (if spartan) PDF
    | locally. That is a deliberate shipping state, not a stub left behind: the
    | whole chain — issue, storno, download, retry — runs and is tested against
    | it, and the day a live Billingo key lands in the production env the same
    | code issues real documents. The alternative, holding the feature until an
    | account exists, would have left `pdf_path` null and the tenant's download
    | 404ing for however long that took.
    |
    | Going live is therefore three env values: INVOICING_PLATFORM_PROVIDER,
    | INVOICING_PLATFORM_API_KEY and the block id from that account.
    |
    */

    'platform' => [

        'provider' => env('INVOICING_PLATFORM_PROVIDER', InvoiceProvider::Sandbox->value),

        'api_key' => env('INVOICING_PLATFORM_API_KEY'),

        // Who the commission invoice is issued by. Configuration rather than a
        // superadmin screen: it is one company's registration data, it changes
        // approximately never, and it belongs with the credential it is used
        // alongside rather than in a table with its own UI and policy.
        'seller_name' => env('INVOICING_PLATFORM_SELLER_NAME'),
        'seller_tax_number' => env('INVOICING_PLATFORM_SELLER_TAX_NUMBER'),
        'seller_address' => env('INVOICING_PLATFORM_SELLER_ADDRESS'),

        // slot4u's commission is a domestic VAT-bearing service (docs/10 §15.1);
        // the invoice itself carries the rate, this is what the provider is told.
        'vat_key' => env('INVOICING_PLATFORM_VAT_KEY', '27'),

        // Billingo numbers every document into a block, so a live setup needs
        // one from slot4u's OWN account. Null under the sandbox, which numbers
        // its own.
        'block_id' => env('INVOICING_PLATFORM_BLOCK_ID'),

    ],

];
