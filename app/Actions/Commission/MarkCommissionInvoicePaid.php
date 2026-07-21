<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\AuditAction;
use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Models\CommissionInvoice;
use App\Models\TenantBillingPeriod;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;

/**
 * Records payment of a commission invoice (docs/10 §6.6): the invoice and its
 * billing period both move to `paid`. A tenant suspended for non-payment is
 * reactivated once it has no other outstanding invoice, so paying up restores
 * service without a manual superadmin step. The settlement is audited (J8b).
 */
final class MarkCommissionInvoicePaid
{
    public function __construct(
        private readonly ReactivateTenantIfInvoicesCleared $reactivate,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(CommissionInvoice $invoice, ?string $method = null): CommissionInvoice
    {
        // Whether this payment clears a dunning-overdue invoice — only that ties the
        // tenant's suspension to non-payment (a manual superadmin suspension must not
        // be auto-lifted just because an invoice got paid).
        $wasOverdue = $invoice->status === CommissionInvoiceStatus::Overdue;
        $fromStatus = $invoice->status;

        $invoice->status = CommissionInvoiceStatus::Paid;
        $invoice->paid_at = Carbon::now();
        if ($method !== null) {
            $invoice->paid_method = $method;
        }
        $invoice->save();

        TenantBillingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('period', $invoice->period)
            ->update(['status' => BillingPeriodStatus::Paid->value]);

        if ($wasOverdue) {
            ($this->reactivate)($invoice->tenant_id);
        }

        $this->audit->record(
            action: AuditAction::CommissionInvoicePaid,
            auditable: $invoice,
            oldValues: ['status' => $fromStatus->value],
            newValues: ['status' => CommissionInvoiceStatus::Paid->value, 'method' => $method],
            tenantId: $invoice->tenant_id,
        );

        return $invoice;
    }
}
