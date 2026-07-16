<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingPeriodStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BillingPeriodFilterRequest;
use App\Models\BookingCommissionItem;
use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Services\Commission\BillingPeriodClock;
use App\Services\Commission\BuildTenantBillingOverview;
use App\Services\Commission\TenantBillingOverview;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tenant commission transparency dashboard (SLO-70, docs/10 §9). Answers, for the
 * selected period, the questions a tenant will actually ask: how much have I
 * turned over, how much of the free allowance is left, what have I accrued, how
 * far is the cap, what rate am I on and why — plus the itemised ledger behind the
 * number and the monthly invoices issued from it.
 *
 * Read-only. Lives behind auth + ensure.user.tenant + can:billing.view
 * (routes/tenant.php); every query is tenant-scoped by BelongsToTenant, so a
 * cross-tenant period or invoice is a 404. The figures come from the aggregate the
 * recompute maintains synchronously on each booking transition (§5.4), so the page
 * is live without polling.
 */
class BillingController extends Controller
{
    /** Ledger rows per page — the list is a reconciliation aid, not a report. */
    private const PER_PAGE = 20;

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly BuildTenantBillingOverview $buildOverview,
        private readonly BillingPeriodClock $clock,
    ) {}

    public function index(BillingPeriodFilterRequest $request): Response
    {
        $tenant = $this->currentTenant();
        Gate::authorize('viewAny', TenantBillingPeriod::class);

        $periods = $this->availablePeriods($tenant);
        $selected = $this->selectedPeriod($request->validated('period'), $periods);

        $aggregate = TenantBillingPeriod::query()->where('period', $selected)->first();
        $overview = ($this->buildOverview)($tenant, $selected, $aggregate);

        $items = BookingCommissionItem::query()
            ->where('period', $selected)
            // Eager-loaded: the list renders one booking per row (DoD: no N+1).
            ->with('booking:id,code,starts_at')
            ->orderByDesc('realized_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (BookingCommissionItem $item): array => $this->itemRow($item));

        return Inertia::render('Admin/Billing/Index', [
            'overview' => $this->overviewProps($overview, $aggregate),
            'items' => $items,
            'invoices' => $this->invoiceRows(),
            'periods' => $periods,
            'filters' => ['period' => $selected],
        ]);
    }

    /**
     * The selected period's ledger as CSV (docs/10 §9 "exportálható") — the shape
     * a tenant reconciles against their own books in a spreadsheet.
     */
    public function export(BillingPeriodFilterRequest $request): StreamedResponse
    {
        $tenant = $this->currentTenant();
        Gate::authorize('viewAny', TenantBillingPeriod::class);

        $selected = $this->selectedPeriod($request->validated('period'), $this->availablePeriods($tenant));

        $headers = [
            trans('app.admin.billing.export.booking_code'),
            trans('app.admin.billing.export.realized_at'),
            trans('app.admin.billing.export.amount'),
            trans('app.admin.billing.export.rate'),
            trans('app.admin.billing.export.state'),
            trans('app.admin.billing.export.currency'),
        ];

        $filename = "slot4u-commission-{$tenant->slug}-{$selected}.csv";

        return response()->streamDownload(function () use ($selected, $tenant, $headers): void {
            $out = fopen('php://output', 'wb');

            // BOM so Excel opens the Hungarian accents as UTF-8 rather than latin1.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');

            BookingCommissionItem::query()
                ->where('period', $selected)
                ->with('booking:id,code,starts_at')
                ->orderBy('realized_at')
                ->orderBy('id')
                // lazy(), not chunkById(): the latter reorders by the primary key
                // and would silently drop the chronological sort a tenant
                // reconciles against. Still streamed — the whole ledger never
                // lands in memory at once.
                ->lazy(500)
                ->each(function (BookingCommissionItem $item) use ($out, $tenant): void {
                    fputcsv($out, [
                        // ?? on -> is isset-semantics: a booking row deleted
                        // out from under the ledger exports as blank, not 500.
                        $item->booking->code ?? '',
                        $item->realized_at->setTimezone($tenant->timezone)->format('Y-m-d H:i'),
                        $this->minorToDecimal($item->amount_minor),
                        $this->bpsToPercent($item->rate_bps),
                        trans('app.commission_item_state.'.$item->state->value),
                        $item->currency,
                    ], ';');
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Download a commission invoice's PDF. The file is produced by the external
     * invoicing provider (SLO-41) and only referenced here — until that lands
     * `pdf_path` is always null, so this 404s rather than promising a document
     * that does not exist. Route-bound invoice is tenant-scoped → cross-tenant 404.
     */
    public function invoicePdf(string $tenant, CommissionInvoice $invoice): StreamedResponse
    {
        $this->currentTenant();
        Gate::authorize('view', $invoice);

        $path = $invoice->pdf_path;
        abort_if($path === null || ! Storage::exists($path), 404);

        return Storage::download($path, "slot4u-{$invoice->period}.pdf");
    }

    private function currentTenant(): Tenant
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);

        return $tenant;
    }

    /**
     * Every period the tenant has a row for, newest first, plus the one currently
     * accruing — which has no aggregate until its first billable booking.
     *
     * @return list<string>
     */
    private function availablePeriods(Tenant $tenant): array
    {
        /** @var list<string> $periods */
        $periods = TenantBillingPeriod::query()
            ->orderByDesc('period')
            ->pluck('period')
            ->all();

        $current = $this->clock->currentPeriod($tenant->timezone);

        if (! in_array($current, $periods, true)) {
            array_unshift($periods, $current);
        }

        return $periods;
    }

    /**
     * @param  list<string>  $periods
     */
    private function selectedPeriod(?string $requested, array $periods): string
    {
        if ($requested === null) {
            // Newest first, so [0] is the period the tenant is accruing into.
            return $periods[0];
        }

        // A well-formed period the tenant has no row for is an unknown identifier,
        // not a validation error (see BillingPeriodFilterRequest).
        abort_unless(in_array($requested, $periods, true), 404);

        return $requested;
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewProps(TenantBillingOverview $overview, ?TenantBillingPeriod $aggregate): array
    {
        return [
            'period' => $overview->period,
            'currency' => $overview->currency,
            'configured' => $overview->configured,
            // A period with no billable booking yet has no row — it is open.
            'status' => ($aggregate instanceof TenantBillingPeriod ? $aggregate->status : BillingPeriodStatus::Open)->value,
            'turnover_minor' => $overview->turnoverMinor,
            'billable_base_minor' => $overview->billableBaseMinor,
            'commission_minor' => $overview->commissionMinor,
            'free_threshold_minor' => $overview->freeThresholdMinor,
            'free_remaining_minor' => $overview->freeRemainingMinor,
            'monthly_cap_minor' => $overview->monthlyCapMinor,
            'cap_remaining_minor' => $overview->capRemainingMinor,
            'cap_reached' => $overview->capReached,
            'threshold_reached' => $overview->thresholdReached,
            'rate_bps' => $overview->rateBps,
            'raised_rate_bps' => $overview->raisedRateBps,
            'integration_codes' => $overview->integrationCodes,
            'recomputed_at' => $overview->recomputedAt?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(BookingCommissionItem $item): array
    {
        return [
            'id' => $item->id,
            'booking_code' => $item->booking->code ?? null,
            'booking_starts_at' => $item->booking->starts_at?->toIso8601String() ?? null,
            'realized_at' => $item->realized_at->toIso8601String(),
            'amount_minor' => $item->amount_minor,
            'rate_bps' => $item->rate_bps,
            'state' => $item->state->value,
            'currency' => $item->currency,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceRows(): array
    {
        return CommissionInvoice::query()
            ->orderByDesc('period')
            ->get()
            ->map(fn (CommissionInvoice $invoice): array => [
                'id' => $invoice->id,
                'period' => $invoice->period,
                'commission_net_minor' => $invoice->commission_net_minor,
                'vat_minor' => $invoice->vat_minor,
                'vat_bps' => $invoice->vat_bps,
                'total_gross_minor' => $invoice->total_gross_minor,
                'currency' => $invoice->currency,
                'status' => $invoice->status->value,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'due_at' => $invoice->due_at?->toIso8601String(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'has_pdf' => $invoice->pdf_path !== null,
            ])
            ->all();
    }

    /** Minor units as a plain decimal — spreadsheet-friendly, never a float. */
    private function minorToDecimal(int $minor): string
    {
        return $this->hundredths($minor);
    }

    private function bpsToPercent(int $bps): string
    {
        return $this->hundredths($bps).'%';
    }

    /**
     * Render a hundredths-scaled integer (minor units, basis points) with a comma
     * decimal separator. Integer arithmetic throughout — dividing money by 100 in
     * floating point is exactly what docs/01 §6 forbids.
     */
    private function hundredths(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        return $sign.intdiv($abs, 100).','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
