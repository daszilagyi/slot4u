<?php

namespace App\Http\Controllers\Super;

use App\Actions\Commission\MarkCommissionInvoicePaid;
use App\Actions\Commission\ResendCommissionInvoice;
use App\Actions\Commission\VoidCommissionInvoice;
use App\Console\Commands\DunningSweep;
use App\Enums\CommissionInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\CommissionInvoiceFilterRequest;
use App\Http\Requests\Super\MarkCommissionInvoicePaidRequest;
use App\Http\Requests\Super\VoidCommissionInvoiceRequest;
use App\Jobs\IssueCommissionInvoiceDocument;
use App\Models\CommissionInvoice;
use App\Services\Invoicing\PlatformInvoicing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Superadmin commission-invoice management (J8b, docs/10 §10): the cross-tenant
 * list of slot4u→tenant invoices with manual settlement, void and resend, plus
 * the dunning countdown that shows when non-payment will suspend a tenant.
 *
 * Lives behind auth + ensure.superadmin (routes/admin.php), so there is no bound
 * tenant — every query drops the BelongsToTenant scope explicitly (the J6/J7
 * pattern) rather than leaning on the scope's no-op. Settle/void/resend are gated
 * to outstanding invoices; a settled or voided one has nothing left to act on.
 */
class CommissionInvoiceController extends Controller
{
    public function index(CommissionInvoiceFilterRequest $request): Response
    {
        Gate::authorize('manageAny', CommissionInvoice::class);

        $status = $request->validated('status');
        $period = $request->validated('period');
        $tenantId = $request->validated('tenant_id');

        $now = Carbon::now();

        $invoices = CommissionInvoice::query()
            ->withoutGlobalScopes()
            // Eager-load the tenant name/slug rendered per row (DoD: no N+1).
            ->with('tenant:id,name,slug')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($period, fn ($query) => $query->where('period', $period))
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            // Newest first; overdue invoices float up via the status filter.
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (CommissionInvoice $invoice) => $this->invoiceRow($invoice, $now));

        return Inertia::render('Super/CommissionInvoices/Index', [
            'invoices' => $invoices,
            'filters' => ['status' => $status, 'period' => $period, 'tenant_id' => $tenantId],
            'statuses' => array_map(fn (CommissionInvoiceStatus $s) => $s->value, CommissionInvoiceStatus::cases()),
            'grace_days' => DunningSweep::GRACE_DAYS,
            // Whether slot4u's own invoicing channel is actually live (SLO-143).
            // Surfaced rather than assumed: under the sandbox the documents are
            // real files with no legal standing, and a screen that showed them
            // without saying so would be the most expensive kind of quiet.
            'invoicing_live' => app(PlatformInvoicing::class)->isLive(),
        ]);
    }

    public function markPaid(
        MarkCommissionInvoicePaidRequest $request,
        CommissionInvoice $invoice,
        MarkCommissionInvoicePaid $markPaid,
    ): RedirectResponse {
        Gate::authorize('manage', $invoice);

        if (! $invoice->status->isOutstanding()) {
            return back()->withErrors(['invoice' => __('super.commission_invoices.error.not_outstanding')]);
        }

        $markPaid($invoice, $request->validated('method'));

        return back();
    }

    public function void(
        VoidCommissionInvoiceRequest $request,
        CommissionInvoice $invoice,
        VoidCommissionInvoice $void,
    ): RedirectResponse {
        Gate::authorize('manage', $invoice);

        if (! $invoice->status->isOutstanding()) {
            return back()->withErrors(['invoice' => __('super.commission_invoices.error.not_outstanding')]);
        }

        $void($invoice, $request->validated('reason'));

        return back();
    }

    public function resend(CommissionInvoice $invoice, ResendCommissionInvoice $resend): RedirectResponse
    {
        Gate::authorize('manage', $invoice);

        if (! $invoice->status->isOutstanding()) {
            return back()->withErrors(['invoice' => __('super.commission_invoices.error.not_outstanding')]);
        }

        $resend($invoice);

        return back();
    }

    /**
     * Ask the invoicing provider for the document again (SLO-143).
     *
     * Only for an invoice that has none. The job is idempotent anyway, but
     * refusing here means the button is never offered for an invoice that has
     * one — a numbering series has no gaps to give back, and "retry" is the one
     * word that should never be able to produce a second document for one debt.
     */
    public function retryDocument(CommissionInvoice $invoice): RedirectResponse
    {
        Gate::authorize('manage', $invoice);

        if ($invoice->provider_ref !== null || $invoice->status === CommissionInvoiceStatus::Void) {
            return back()->withErrors(['invoice' => __('super.commission_invoices.error.document_not_retryable')]);
        }

        IssueCommissionInvoiceDocument::dispatch($invoice->getKey());

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(CommissionInvoice $invoice, Carbon $now): array
    {
        $outstanding = $invoice->status->isOutstanding();

        return [
            'id' => $invoice->id,
            'tenant' => $invoice->tenant === null ? null : [
                'id' => $invoice->tenant->id,
                'name' => $invoice->tenant->name,
                'slug' => $invoice->tenant->slug,
            ],
            'period' => $invoice->period,
            'commission_net_minor' => $invoice->commission_net_minor,
            'vat_minor' => $invoice->vat_minor,
            'total_gross_minor' => $invoice->total_gross_minor,
            'currency' => $invoice->currency,
            'status' => $invoice->status->value,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'paid_method' => $invoice->paid_method,
            // Actions apply only while the invoice is still outstanding.
            'can_manage' => $outstanding,
            // The external DOCUMENT's state, which is not the invoice's status
            // (SLO-143): the debt can be perfectly normal while the paperwork
            // failed, and only one of those two is what dunning acts on.
            'document' => [
                'number' => $invoice->number,
                'issued' => $invoice->provider_ref !== null,
                'stornoed' => $invoice->storno_ref !== null,
                'error' => $invoice->provider_error,
                // Retryable exactly while nothing has been issued: a second
                // attempt after success would mint a second number for one debt.
                'can_retry' => $invoice->provider_ref === null
                    && $invoice->status !== CommissionInvoiceStatus::Void,
            ],
            // Dunning countdown, computed server-side so the view stays a pure
            // render (no Date.now() during render — react-hooks/purity).
            'dunning' => $this->dunning($invoice, $now),
        ];
    }

    /**
     * Days overdue and days-until-suspension for an overdue invoice, or null when
     * the invoice is not overdue (docs/10 §6.6, §15.3 grace window).
     *
     * @return array{days_overdue: int, days_until_suspension: int, suspend_at: string}|null
     */
    private function dunning(CommissionInvoice $invoice, Carbon $now): ?array
    {
        if ($invoice->status !== CommissionInvoiceStatus::Overdue || $invoice->due_at === null) {
            return null;
        }

        $suspendAt = $invoice->due_at->copy()->addDays(DunningSweep::GRACE_DAYS);
        $secondsToSuspend = $now->diffInSeconds($suspendAt, false);

        return [
            'days_overdue' => (int) floor($invoice->due_at->diffInDays($now)),
            'days_until_suspension' => $secondsToSuspend > 0 ? (int) ceil($secondsToSuspend / 86_400) : 0,
            'suspend_at' => $suspendAt->toIso8601String(),
        ];
    }
}
