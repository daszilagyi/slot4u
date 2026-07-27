<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Contracts;

use App\Enums\InvoiceProvider;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\IssuedInvoice;

/**
 * The invoicing provider abstraction (SLO-41 / SLO-133). Everything above it —
 * the issue/storno jobs, the retry action, the admin and members views — is
 * provider agnostic, so the Számlázz.hu Agent API client (SLO-134) is a new
 * implementation rather than a rewrite (docs/01 §4).
 *
 * Both methods throw on refusal; the caller records the failure on the invoice
 * and keeps it retryable.
 */
interface InvoiceIssuer
{
    public function provider(): InvoiceProvider;

    /** Issue an invoice for a settled payment. */
    public function issue(InvoiceRequest $request): IssuedInvoice;

    /** Void an already issued invoice with a storno document. */
    public function storno(Invoice $invoice): IssuedInvoice;
}
