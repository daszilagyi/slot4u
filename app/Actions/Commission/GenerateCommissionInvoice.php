<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\CommissionItemState;
use App\Events\CommissionInvoiceIssued;
use App\Models\BookingCommissionItem;
use App\Models\CommissionInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Closes a tenant's billing period and issues the monthly commission invoice
 * (docs/10 §6.5). slot4u is the vendor, so the commission is its net SaaS fee
 * and the invoice adds slot4u's Hungarian VAT on top (§15.1, net + 27%).
 *
 * Steps: finalise the aggregate (RecomputeTenantPeriod), then — if any commission
 * is owed — insert the invoice, freeze the period as `invoiced`, and raise
 * CommissionInvoiceIssued (tenant email; external provider is a later slice).
 * A period with zero commission is voided with no invoice.
 *
 * Idempotent per (tenant, period): a period already closed keeps its existing
 * invoice, and a concurrent double-close resolves to the single unique row.
 */
final class GenerateCommissionInvoice
{
    /** slot4u's Hungarian VAT rate in basis points (docs/10 §15.1). */
    public const int VAT_BPS = 2700;

    /** Payment term in days from issue (docs/10 §15.3). */
    public const int PAYMENT_DUE_DAYS = 8;

    public function __construct(private readonly RecomputeTenantPeriod $recompute) {}

    public function __invoke(int $tenantId, string $period): ?CommissionInvoice
    {
        $aggregate = ($this->recompute)($tenantId, $period);

        if ($aggregate === null) {
            return null;
        }

        // Already closed (invoiced/paid/overdue/void) → idempotent: return the
        // existing invoice, if any. A void period never had one.
        if (! $aggregate->status->isRecomputable()) {
            return CommissionInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('period', $period)
                ->first();
        }

        // Nothing owed → void the period, no invoice (docs/10 §6.5).
        if ($aggregate->commission_minor === 0) {
            $aggregate->status = BillingPeriodStatus::Void;
            $aggregate->save();

            return null;
        }

        $net = $aggregate->commission_minor;
        $vatMinor = intdiv($net * self::VAT_BPS, 10_000);
        $now = Carbon::now();

        $invoice = new CommissionInvoice([
            'period' => $period,
            'turnover_minor' => $aggregate->turnover_minor,
            'billable_base_minor' => $aggregate->billable_base_minor,
            'commission_net_minor' => $net,
            'vat_bps' => self::VAT_BPS,
            'vat_minor' => $vatMinor,
            'total_gross_minor' => $net + $vatMinor,
            'currency' => $this->periodCurrency($tenantId, $period),
            'status' => CommissionInvoiceStatus::Issued,
            'issued_at' => $now,
            'due_at' => $now->copy()->addDays(self::PAYMENT_DUE_DAYS),
        ]);
        $invoice->tenant_id = $tenantId;

        try {
            $invoice->save();
        } catch (QueryException) {
            // A concurrent close already issued it — return the winner, don't re-close.
            return CommissionInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('period', $period)
                ->first();
        }

        $aggregate->status = BillingPeriodStatus::Invoiced;
        $aggregate->invoice_id = $invoice->getKey();
        $aggregate->save();

        CommissionInvoiceIssued::dispatch($invoice);

        return $invoice;
    }

    /**
     * The invoice currency: the one snapshotted on the period's ledger entries
     * (a tenant bills in a single currency — multi-currency is out of MVP, §15.7).
     */
    private function periodCurrency(int $tenantId, string $period): string
    {
        return BookingCommissionItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('period', $period)
            ->where('state', CommissionItemState::Billable->value)
            ->value('currency') ?? 'HUF';
    }
}
