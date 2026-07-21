<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\CommissionInvoice;
use App\Models\Tenant;

/**
 * Lifts a non-payment suspension once a tenant owes nothing more (docs/10 §6.6):
 * only when the tenant is suspended and no invoice is still outstanding
 * (issued/overdue). Shared by MarkCommissionInvoicePaid and VoidCommissionInvoice
 * — settling or voiding the debt both clear an invoice from the outstanding set,
 * so the rule must not drift between the two paths.
 *
 * Callers invoke this only when the cleared invoice was overdue: that is what
 * ties the suspension to non-payment. A manual superadmin suspension is never
 * auto-lifted. Runs with the tenant scope off (superadmin/queue context).
 */
final class ReactivateTenantIfInvoicesCleared
{
    public function __construct(private readonly ChangeTenantStatus $changeStatus) {}

    public function __invoke(int $tenantId): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);

        if (! $tenant instanceof Tenant || $tenant->status !== TenantStatus::Suspended) {
            return;
        }

        $stillOutstanding = CommissionInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [CommissionInvoiceStatus::Issued->value, CommissionInvoiceStatus::Overdue->value])
            ->exists();

        if (! $stillOutstanding) {
            ($this->changeStatus)($tenant, TenantStatus::Active);
        }
    }
}
