<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\AuditAction;
use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Models\CommissionInvoice;
use App\Models\TenantBillingPeriod;
use App\Services\Audit\AuditLogger;

/**
 * Voids an outstanding commission invoice (J8b, docs/10 §10): the invoice and its
 * billing period both move to `void`, mirroring the zero-commission void that
 * GenerateCommissionInvoice already produces (§6.5). The period is NOT reopened —
 * closed periods stay accounting-stable (§8.2), so the same period is never
 * re-invoiced; a later correction flows into the current open period instead.
 *
 * Voiding cancels the debt, so it lifts a non-payment suspension the same way
 * payment does: only when the voided invoice was overdue (the state that drives
 * dunning) and the tenant is left with no other outstanding invoice. A manual
 * superadmin suspension is never auto-lifted.
 *
 * Callers must pass an outstanding (issued/overdue) invoice — settled invoices are
 * a refund case, out of scope here.
 */
final class VoidCommissionInvoice
{
    public function __construct(
        private readonly ReactivateTenantIfInvoicesCleared $reactivate,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(CommissionInvoice $invoice, ?string $reason = null): CommissionInvoice
    {
        // Only an overdue invoice ties the tenant's suspension to non-payment;
        // clearing it may restore service (mirrors MarkCommissionInvoicePaid).
        $wasOverdue = $invoice->status === CommissionInvoiceStatus::Overdue;
        $fromStatus = $invoice->status;

        $invoice->status = CommissionInvoiceStatus::Void;
        $invoice->save();

        TenantBillingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('period', $invoice->period)
            ->update(['status' => BillingPeriodStatus::Void->value]);

        if ($wasOverdue) {
            ($this->reactivate)($invoice->tenant_id);
        }

        $this->audit->record(
            action: AuditAction::CommissionInvoiceVoided,
            auditable: $invoice,
            oldValues: ['status' => $fromStatus->value],
            newValues: ['status' => CommissionInvoiceStatus::Void->value, 'reason' => $reason],
            tenantId: $invoice->tenant_id,
        );

        return $invoice;
    }
}
