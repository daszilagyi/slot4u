<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Contracts;

use App\Enums\InvoiceProvider;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\IssuedInvoice;
use App\Services\Invoicing\StornoRequest;

/**
 * The invoicing provider abstraction (SLO-133). Everything above it — the
 * issue/storno jobs, the retry action, the admin and members views — is provider
 * agnostic, so Billingo (SLO-167) was a new implementation rather than a rewrite
 * (docs/01 §4).
 *
 * Both methods throw on refusal; the caller records the failure on the invoice
 * and keeps it retryable.
 *
 * An adapter never reaches into models at all: everything it needs arrives in a
 * request object, the seller's settings included. `storno()` used to take an
 * `Invoice` model, which broke the rule and then broke the abstraction — slot4u's
 * own commission invoice lives in a different table, and there was no way to void
 * one without teaching every adapter a second Eloquent class (SLO-143).
 */
interface InvoiceIssuer
{
    public function provider(): InvoiceProvider;

    /** Issue an invoice for a settled payment. */
    public function issue(InvoiceRequest $request): IssuedInvoice;

    /** Void an already issued document with a storno document. */
    public function storno(StornoRequest $request): IssuedInvoice;
}
