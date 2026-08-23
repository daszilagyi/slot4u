<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceIssuerManager;
use App\Services\Invoicing\StornoRequest;
use App\Settings\TenantInvoicingSettings;
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
 * Voids an issued invoice with a storno document (SLO-133), triggered when a
 * payment has been refunded in full (a partial refund would need a corrective
 * invoice, which is out of scope — see the SLO-133 notes).
 *
 * Queued and `afterCommit` like the issuing job, idempotent (an already stornoed
 * invoice is left alone), and a refusal is recorded on the invoice so the admin
 * sees it instead of believing the customer was credited on paper.
 */
class StornoInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly int $invoiceId)
    {
        $this->afterCommit();
    }

    public function handle(InvoiceIssuerManager $issuers): void
    {
        $invoice = Invoice::withoutGlobalScopes()->find($this->invoiceId);

        // Only a live invoice can be voided: a pending/failed one was never
        // issued, and an already stornoed one is done.
        if (! $invoice instanceof Invoice || ! $invoice->status->isLive()) {
            return;
        }

        $tenant = Tenant::withoutGlobalScopes()->withTrashed()->find($invoice->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        try {
            $storno = $issuers->for($invoice->provider)->storno(new StornoRequest(
                seller: TenantInvoicingSettings::fromArray($tenant->invoicing),
                providerRef: $invoice->provider_ref,
                number: $invoice->number,
                amountMinor: $invoice->amount_minor,
                currency: $invoice->currency,
            ));
        } catch (Throwable $e) {
            Log::warning('Invoice storno refused by the provider', [
                'invoice_id' => $invoice->getKey(),
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // The invoice stays `issued` — it really is still live at the provider.
            // The error is what the admin acts on.
            $invoice->error = Str::limit($e->getMessage(), 490);
            $invoice->saveQuietly();

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 60);
            }

            return;
        }

        $invoice->status = InvoiceStatus::Storno;
        $invoice->storno_number = $storno->number;
        $invoice->stornoed_at = Carbon::now();
        $invoice->error = null;

        if ($storno->pdf !== null) {
            $path = "tenants/{$invoice->tenant_id}/invoices/storno-{$invoice->getKey()}-".Str::lower(Str::random(8)).'.pdf';
            Storage::disk((string) config('invoicing.disk'))->put($path, $storno->pdf);
            $invoice->storno_pdf_path = $path;
        }

        $invoice->saveQuietly();
    }
}
