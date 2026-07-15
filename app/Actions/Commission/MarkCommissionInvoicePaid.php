<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use Illuminate\Support\Carbon;

/**
 * Records payment of a commission invoice (docs/10 §6.6): the invoice and its
 * billing period both move to `paid`. A tenant suspended for non-payment is
 * reactivated once it has no other outstanding invoice, so paying up restores
 * service without a manual superadmin step.
 */
final class MarkCommissionInvoicePaid
{
    public function __construct(private readonly ChangeTenantStatus $changeStatus) {}

    public function __invoke(CommissionInvoice $invoice, ?string $method = null): CommissionInvoice
    {
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

        $this->reactivateIfCleared($invoice);

        return $invoice;
    }

    /**
     * Lift a non-payment suspension once the tenant has settled everything: only
     * when it is suspended and no other invoice is still outstanding.
     */
    private function reactivateIfCleared(CommissionInvoice $invoice): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($invoice->tenant_id);

        if (! $tenant instanceof Tenant || $tenant->status !== TenantStatus::Suspended) {
            return;
        }

        $stillOutstanding = CommissionInvoice::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->whereIn('status', [CommissionInvoiceStatus::Issued->value, CommissionInvoiceStatus::Overdue->value])
            ->exists();

        if (! $stillOutstanding) {
            ($this->changeStatus)($tenant, TenantStatus::Active);
        }
    }
}
