<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Contracts;

use App\Enums\InvoiceProvider;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\IssuedInvoice;
use App\Settings\TenantInvoicingSettings;

/**
 * The invoicing provider abstraction (SLO-133). Everything above it — the
 * issue/storno jobs, the retry action, the admin and members views — is provider
 * agnostic, so Billingo (SLO-167) was a new implementation rather than a rewrite
 * (docs/01 §4).
 *
 * Both methods throw on refusal; the caller records the failure on the invoice
 * and keeps it retryable.
 *
 * An adapter never reaches into models for configuration: the seller's settings
 * are handed to it. `storno()` takes them explicitly for that reason — the
 * built-in sandbox needed no credential, but a real provider does, and letting
 * the adapter load a tenant would put a query behind an interface that is
 * supposed to be a pure translation layer.
 */
interface InvoiceIssuer
{
    public function provider(): InvoiceProvider;

    /** Issue an invoice for a settled payment. */
    public function issue(InvoiceRequest $request): IssuedInvoice;

    /** Void an already issued invoice with a storno document. */
    public function storno(Invoice $invoice, TenantInvoicingSettings $seller): IssuedInvoice;
}
