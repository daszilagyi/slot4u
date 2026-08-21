<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\BillingDetails;
use App\Services\Invoicing\InvoiceIssuerManager;
use App\Services\Invoicing\InvoiceRequest;
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
 * Issues a recorded invoice at the provider and stores the returned document
 * (SLO-133).
 *
 * Queued and `afterCommit`, so nothing is issued for a payment whose transaction
 * rolled back. Retries a few times with a backoff (a provider blip is common);
 * once the attempts run out the invoice is marked `failed` with the provider's
 * message, and the admin can retry it by hand from the booking page — the
 * obligation stays visible either way.
 *
 * Takes the invoice id (not the model) so a retry always reads the current row.
 */
class IssueInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A provider blip is normal; three attempts with a growing pause. */
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

        // Idempotent: an already issued (or stornoed) invoice is never re-issued —
        // only a pending one, or a failed one an admin asked to retry.
        if (! $invoice instanceof Invoice || $invoice->status === InvoiceStatus::Issued || $invoice->status === InvoiceStatus::Storno) {
            return;
        }

        $booking = Booking::withoutGlobalScopes()->with('service:id,name')->find($invoice->booking_id);
        $tenant = Tenant::withoutGlobalScopes()->withTrashed()->find($invoice->tenant_id);

        if (! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            return;
        }

        $seller = TenantInvoicingSettings::fromArray($tenant->invoicing);

        $request = new InvoiceRequest(
            seller: $seller,
            buyerName: $booking->contactName() ?? '—',
            buyerEmail: $booking->contactEmail(),
            // `name` is non-nullable on the service, so a missing service (deleted
            // out from under the booking) is the only "—" case here.
            itemName: $booking->service !== null ? $booking->service->name : '—',
            amountMinor: $invoice->amount_minor,
            currency: $invoice->currency,
            // Dated in the tenant's own calendar, like every other tenant-facing
            // date (docs/01 §7).
            issueDate: Carbon::now()->setTimezone($tenant->timezone)->toDateString(),
            // What the buyer asked for at booking time (SLO-168). Read from the
            // booking rather than from the customer's profile: an issued
            // document records what was true then, and must not change because
            // somebody later moved house.
            billing: BillingDetails::fromBooking($booking),
        );

        try {
            $issued = $issuers->for($invoice->provider)->issue($request);
        } catch (Throwable $e) {
            $this->recordFailure($invoice, $e);

            return;
        }

        $invoice->number = $issued->number;
        $invoice->provider_ref = $issued->providerRef;
        $invoice->status = InvoiceStatus::Issued;
        $invoice->issued_at = Carbon::now();
        $invoice->error = null;

        if ($issued->pdf !== null) {
            $invoice->pdf_path = $this->storePdf($invoice, $issued->pdf, 'szamla');
        }

        $invoice->saveQuietly();
    }

    /**
     * Store an invoice document on the private disk under the tenant's prefix —
     * never the public one: it carries the customer's name and what they paid.
     */
    private function storePdf(Invoice $invoice, string $contents, string $prefix): string
    {
        $path = "tenants/{$invoice->tenant_id}/invoices/{$prefix}-{$invoice->getKey()}-".Str::lower(Str::random(8)).'.pdf';

        Storage::disk((string) config('invoicing.disk'))->put($path, $contents);

        return $path;
    }

    /**
     * Park the invoice as `failed` with the provider's message — always, not only
     * on the last attempt: the admin must see an uninvoiced payment the moment it
     * happens, and a later automatic attempt simply flips it back to `issued`.
     * The queue is then asked for another attempt while any remain.
     */
    private function recordFailure(Invoice $invoice, Throwable $e): void
    {
        Log::warning('Invoice issuing refused by the provider', [
            'invoice_id' => $invoice->getKey(),
            'attempt' => $this->attempts(),
            'error' => $e->getMessage(),
        ]);

        $invoice->status = InvoiceStatus::Failed;
        $invoice->error = Str::limit($e->getMessage(), 490);
        $invoice->saveQuietly();

        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
        }
    }
}
