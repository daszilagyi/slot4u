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

];
