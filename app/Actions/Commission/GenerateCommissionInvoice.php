<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionCorrectionType;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\CommissionItemState;
use App\Events\CommissionInvoiceIssued;
use App\Jobs\IssueCommissionInvoiceDocument;
use App\Models\BookingCommissionItem;
use App\Models\CommissionCorrection;
use App\Models\CommissionInvoice;
use App\Services\Commission\BillingPeriodClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Closes a tenant's billing period and issues the monthly commission invoice
 * (docs/10 §6.5). slot4u is the vendor, so the commission is its net SaaS fee
 * and the invoice adds slot4u's Hungarian VAT on top (§15.1, net + 27%).
 *
 * Steps: finalise the aggregate (RecomputeTenantPeriod), net off any credits
 * carried in from an already-invoiced period (§8.2), then — if anything is owed —
 * insert the invoice, freeze the period as `invoiced`, queue slot4u's own
 * document at its invoicing provider (SLO-143), and raise
 * CommissionInvoiceIssued (tenant email).
 * A period with nothing owed is voided with no invoice; a credit it could not
 * absorb carries on to the next period.
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

    public function __construct(
        private readonly RecomputeTenantPeriod $recompute,
        private readonly BillingPeriodClock $clock,
    ) {}

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

        // Credits carried in from an already-invoiced period (§8.2) reduce what is
        // payable now; the correction is negative, so this is a subtraction.
        $credit = $aggregate->correction_minor;
        $net = $aggregate->commission_minor + $credit;
        $currency = $this->periodCurrency($tenantId, $period);

        // Nothing owed → void the period, no invoice (docs/10 §6.5). A credit
        // bigger than the month's own commission is not forfeited: the unused
        // remainder moves on to the next period rather than being clamped away.
        if ($net <= 0) {
            if ($net < 0) {
                $this->carryOver($tenantId, $period, $net, $currency);
            }

            $aggregate->status = BillingPeriodStatus::Void;
            $aggregate->save();

            return null;
        }

        $vatMinor = intdiv($net * self::VAT_BPS, 10_000);
        $now = Carbon::now();

        $invoice = new CommissionInvoice([
            'period' => $period,
            'turnover_minor' => $aggregate->turnover_minor,
            'billable_base_minor' => $aggregate->billable_base_minor,
            'correction_minor' => $credit,
            'commission_net_minor' => $net,
            'vat_bps' => self::VAT_BPS,
            'vat_minor' => $vatMinor,
            'total_gross_minor' => $net + $vatMinor,
            'currency' => $currency,
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

        // slot4u's own document, at slot4u's own provider (docs/10 §6.5 step 4,
        // §15.1). Queued and deliberately downstream of everything above: the
        // debt, the closed period and the tenant's email must not wait on — or
        // be undone by — an invoicing provider having a bad morning.
        //
        // Dispatched here rather than from a listener on the event below. The
        // document is part of the invoicing act, not a notification about it,
        // and one caller is easier to reason about for something that must
        // happen exactly once against a numbering series.
        IssueCommissionInvoiceDocument::dispatch($invoice->getKey());

        CommissionInvoiceIssued::dispatch($invoice);

        return $invoice;
    }

    /**
     * Moves a credit the period could not absorb on to the next one, so a
     * refund larger than a month's commission is not lost (docs/10 §8.2). The
     * period is closed right after, which makes this run once.
     */
    private function carryOver(int $tenantId, string $period, int $remaining, string $currency): void
    {
        $next = $this->clock->nextPeriod($period);

        $carry = new CommissionCorrection([
            'type' => CommissionCorrectionType::CarryOver,
            'booking_id' => null,
            'source_period' => $period,
            'period' => $next,
            'commission_delta_minor' => $remaining,
            'currency' => $currency,
        ]);
        $carry->tenant_id = $tenantId;
        // saveQuietly: invoicing runs from a tenant-less scheduled job, and the
        // BelongsToTenant creating hook would stamp any ambient tenant over the
        // one being invoiced.
        $carry->saveQuietly();

        ($this->recompute)($tenantId, $next);
    }

    /**
     * The invoice currency: the one snapshotted on the period's ledger entries
     * (a tenant bills in a single currency — multi-currency is out of MVP, §15.7),
     * falling back to a credit-only period's corrections.
     */
    private function periodCurrency(int $tenantId, string $period): string
    {
        return BookingCommissionItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('period', $period)
            ->where('state', CommissionItemState::Billable->value)
            ->value('currency')
            ?? CommissionCorrection::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('period', $period)
                ->value('currency')
            ?? 'HUF';
    }
}
