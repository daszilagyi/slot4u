<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Commission\VoidCommissionInvoice;
use App\Models\CommissionInvoice;
use App\Services\Invoicing\PlatformInvoicing;
use App\Services\Invoicing\StornoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Voids slot4u's own document for a commission invoice a superadmin cancelled
 * (SLO-143, docs/10 §10).
 *
 * ⚠️ The storno is a SECOND document, and it lands in its own columns. Voiding
 * never touches `pdf_path`: the original invoice was sent to the tenant and
 * entered slot4u's books, and an accounting record that can be silently replaced
 * is not one. What the tenant sees afterwards is both files — the invoice and
 * the document that cancels it — which is the same thing their accountant needs.
 *
 * Like the issuing job, this cannot hold up the decision it follows. The debt is
 * already cancelled by {@see VoidCommissionInvoice} —
 * period voided, suspension possibly lifted — before this runs. A provider
 * refusing the storno leaves paperwork to finish, not a tenant still owing money.
 */
class StornoCommissionInvoiceDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(private readonly int $invoiceId)
    {
        $this->afterCommit();
    }

    public function handle(PlatformInvoicing $platform): void
    {
        $invoice = CommissionInvoice::withoutGlobalScopes()->find($this->invoiceId);

        if (! $invoice instanceof CommissionInvoice) {
            return;
        }

        // Nothing was ever issued (the document failed, or the channel was not
        // configured when the month closed), or it is already voided. Neither is
        // an error: there is simply nothing at the provider to cancel.
        if ($invoice->provider_ref === null || $invoice->storno_ref !== null) {
            return;
        }

        try {
            $storno = $platform->issuer()->storno(new StornoRequest(
                seller: $platform->seller(),
                providerRef: $invoice->provider_ref,
                number: $invoice->number,
                amountMinor: $invoice->commission_net_minor,
                currency: $invoice->currency,
            ));
        } catch (Throwable $e) {
            $this->recordFailure($invoice, $e);

            return;
        }

        $invoice->storno_ref = $storno->providerRef ?? $storno->number;
        $invoice->provider_error = null;

        if ($storno->pdf !== null) {
            $invoice->storno_pdf_path = IssueCommissionInvoiceDocument::storePdf($invoice, $storno->pdf, 'storno');
        }

        $invoice->save();
    }

    private function recordFailure(CommissionInvoice $invoice, Throwable $e): void
    {
        Log::warning('Commission invoice storno refused by the provider', [
            'commission_invoice_id' => $invoice->getKey(),
            'tenant_id' => $invoice->tenant_id,
            'attempt' => $this->attempts(),
            'error' => $e->getMessage(),
        ]);

        // The invoice stays voided in slot4u — the debt is cancelled either way.
        // The column records that the document at the provider still stands.
        $invoice->provider_error = Str::limit($e->getMessage(), 490);
        $invoice->save();

        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
