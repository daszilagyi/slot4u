<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Tenancy\TenantManager;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Members area — a logged-in customer's own invoices (SLO-133). Self-scoped
 * through the booking's customer_id, exactly like "my payments" (SLO-132): an
 * invoice carries no customer of its own, and a guest booking has no owner.
 *
 * An invoice that has not been issued yet (pending/failed) is the tenant's
 * problem, not something to show the customer as a document — the list only
 * carries issued and stornoed ones.
 */
class MyInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $timezone = app(TenantManager::class)->current()->timezone;

        /** @var Collection<int, Invoice> $invoices */
        $invoices = $this->ownedInvoices($request)
            ->with(['booking:id,code,service_id', 'booking.service:id,name'])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Tenant/My/Invoices', [
            'invoices' => $invoices->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->storno_number ?? $invoice->number,
                'booking_code' => $invoice->booking?->code,
                'service_name' => $invoice->booking?->service?->name,
                'amount_minor' => $invoice->amount_minor,
                'currency' => $invoice->currency,
                'status' => $invoice->status->value,
                'issued_local' => $this->localDateTime($invoice->issued_at, $timezone),
                'has_pdf' => $invoice->downloadablePath() !== null,
            ])->values(),
        ]);
    }

    /**
     * Stream the customer's own invoice PDF. Not a public URL: the document names
     * the customer and what they paid, so it is served behind auth and the
     * self-scope — someone else's invoice 404s like a cross-tenant id.
     */
    public function download(Request $request, string $tenant, Invoice $invoice): StreamedResponse
    {
        abort_unless($this->ownedInvoices($request)->whereKey($invoice->getKey())->exists(), 404);

        $path = $invoice->downloadablePath();
        abort_if($path === null, 404);

        $disk = Storage::disk((string) config('invoicing.disk'));
        abort_unless($disk->exists($path), 404);

        $number = $invoice->storno_number ?? $invoice->number ?? (string) $invoice->id;

        return $disk->download($path, 'szamla-'.str_replace('/', '-', $number).'.pdf');
    }

    /**
     * The issued invoices behind this customer's own bookings.
     *
     * @return Builder<Invoice>
     */
    private function ownedInvoices(Request $request): Builder
    {
        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Storno->value])
            ->whereHas('booking', fn (Builder $query) => $query->where('customer_id', $request->user()->getKey()));
    }

    private function localDateTime(?CarbonInterface $instant, string $timezone): ?string
    {
        return $instant?->copy()->timezone($timezone)->format('Y-m-d H:i');
    }
}
