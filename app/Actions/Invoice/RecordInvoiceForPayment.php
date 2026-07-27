<?php

declare(strict_types=1);

namespace App\Actions\Invoice;

use App\Enums\Feature;
use App\Jobs\IssueInvoice;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Feature\FeatureResolver;
use App\Services\Invoicing\InvoiceIssuerManager;

/**
 * Records that a settled payment is to be invoiced (SLO-133) and hands the
 * provider call to {@see IssueInvoice}.
 *
 * Like the refund flow (SLO-131), the obligation is written first — inside the
 * transaction that settled the payment — and the external call happens after, so
 * a provider outage leaves a visible, retryable `pending`/`failed` invoice rather
 * than a payment nobody ever invoiced. Idempotent on the payment (unique
 * `payment_id`): re-running finds the existing row and does nothing.
 */
final class RecordInvoiceForPayment
{
    public function __construct(
        private readonly FeatureResolver $features,
        private readonly InvoiceIssuerManager $issuers,
    ) {}

    public function __invoke(Payment $payment): ?Invoice
    {
        $tenant = Tenant::withoutGlobalScopes()->withTrashed()->find($payment->tenant_id);

        // Invoicing is an opt-in integration (and one that raises the commission
        // rate, docs/10 §2.4) — without it the tenant invoices its customers
        // however it did before.
        if (! $tenant instanceof Tenant || ! $this->features->enabled($tenant, Feature::Invoicing)) {
            return null;
        }

        $existing = Invoice::withoutGlobalScopes()->where('payment_id', $payment->getKey())->first();

        if ($existing instanceof Invoice) {
            return $existing;
        }

        $invoice = new Invoice([
            'booking_id' => $payment->booking_id,
            'payment_id' => $payment->getKey(),
            'provider' => $this->issuers->default()->provider(),
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
        ]);
        // Explicit tenant stamp + quiet save: this runs off the request cycle too
        // (the gateway webhook, a queue job), where the creating hook is a no-op.
        $invoice->tenant_id = (int) $payment->tenant_id;
        $invoice->saveQuietly();

        IssueInvoice::dispatch($invoice->getKey());

        return $invoice;
    }
}
