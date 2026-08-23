<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\PlatformInvoicing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issues slot4u's own document for a monthly commission invoice (SLO-143,
 * docs/10 §6.5 step 4).
 *
 * ⚠️ The debt does not depend on this job. `GenerateCommissionInvoice` has
 * already written the row, closed the period and mailed the tenant by the time
 * this runs; dunning reads the row, not the document. A provider outage on the
 * first of the month must not be able to stop slot4u from being owed money, and
 * it must not stop the reminder either — the money arriving and the paperwork
 * existing are two separate facts, and conflating them is how a billing system
 * quietly stops billing.
 *
 * What it does change: `pdf_path`, and therefore whether the tenant's `/billing`
 * download button is real. Until this landed, `pdf_path` was always null and the
 * endpoint 404'd by design — the tenant could not obtain the invoice for a cost
 * it had already paid.
 *
 * Takes the id, not the model, so a retry always reads the current row.
 */
class IssueCommissionInvoiceDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A provider blip is normal; three attempts with a growing pause. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(private readonly int $invoiceId)
    {
        // The invoice row and the period close happen in the caller's
        // transaction. Issuing a document for a close that rolled back would put
        // a real number into slot4u's own numbering series for an invoice that
        // does not exist — and a numbering series has no gaps to give back.
        $this->afterCommit();
    }

    public function handle(PlatformInvoicing $platform): void
    {
        $invoice = CommissionInvoice::withoutGlobalScopes()->find($this->invoiceId);

        if (! $invoice instanceof CommissionInvoice) {
            return;
        }

        // Idempotent: a document already exists. Re-issuing would mint a second
        // number for one debt, which is the one mistake an invoicing integration
        // must never make.
        if ($invoice->provider_ref !== null) {
            return;
        }

        $tenant = Tenant::withoutGlobalScopes()->withTrashed()->find($invoice->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $seller = $platform->seller();

        $request = new InvoiceRequest(
            seller: $seller,
            // The BUYER is the tenant — this is the one invoice in the system
            // where slot4u sells and a tenant buys.
            buyerName: $tenant->name,
            buyerEmail: null,
            itemName: __('app.commission.invoice.item', ['period' => $invoice->period]),
            // Net. The provider adds VAT from `vat_key`, exactly as the row's own
            // vat_minor was computed (docs/10 §15.1) — sending the gross would
            // charge the tenant VAT twice.
            amountMinor: $invoice->commission_net_minor,
            currency: $invoice->currency,
            // Dated when the invoice was issued, not when the queue got to it: a
            // worker backlog must not move a document into the next VAT period.
            issueDate: ($invoice->issued_at ?? Carbon::now())->toDateString(),
        );

        try {
            $issued = $platform->issuer()->issue($request);
        } catch (Throwable $e) {
            $this->recordFailure($invoice, $e);

            return;
        }

        $invoice->number = $issued->number;
        $invoice->provider = $seller->provider?->value;
        $invoice->provider_ref = $issued->providerRef;
        $invoice->provider_error = null;

        if ($issued->pdf !== null) {
            $invoice->pdf_path = self::storePdf($invoice, $issued->pdf, 'jutalek');
        }

        $invoice->save();
    }

    /**
     * Store a commission document on the PRIVATE disk under the tenant's prefix.
     *
     * Same disk and shape as a tenant's own invoices: it names a company and what
     * it owes, and the only way to it is the authorised download route.
     */
    public static function storePdf(CommissionInvoice $invoice, string $contents, string $prefix): string
    {
        $path = "tenants/{$invoice->tenant_id}/commission/{$prefix}-{$invoice->getKey()}-"
            .Str::lower(Str::random(8)).'.pdf';

        Storage::disk((string) config('invoicing.disk'))->put($path, $contents);

        return $path;
    }

    /**
     * Record the refusal on the row — every attempt, not only the last.
     *
     * The superadmin list reads this column, so a provider that started refusing
     * is visible on the first of the month rather than whenever somebody notices
     * that no documents have been produced since spring. The queue is then asked
     * for another attempt while any remain.
     */
    private function recordFailure(CommissionInvoice $invoice, Throwable $e): void
    {
        Log::warning('Commission invoice document refused by the provider', [
            'commission_invoice_id' => $invoice->getKey(),
            'tenant_id' => $invoice->tenant_id,
            'attempt' => $this->attempts(),
            'error' => $e->getMessage(),
        ]);

        $invoice->provider_error = Str::limit($e->getMessage(), 490);
        $invoice->save();

        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
