<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\AuditAction;
use App\Enums\CommissionInvoiceStatus;
use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Notifications\CommissionInvoiceNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\TenantAdminNotifier;

/**
 * Re-sends an outstanding commission invoice to the tenant's admins (J8b,
 * docs/10 §6.5/6.6). The variant matches the current state — the plain `issued`
 * notice while it is still `issued`, the `overdue` dunning reminder once it has
 * lapsed — so a resend never contradicts the invoice's status. Settled and voided
 * invoices are not resendable (nothing left to chase); callers gate on that.
 *
 * Resolves the tenant off the invoice (the superadmin panel binds none) and
 * records the resend, including how many admins were reached.
 */
final class ResendCommissionInvoice
{
    public function __construct(
        private readonly TenantAdminNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int Number of tenant admins the invoice was mailed to.
     */
    public function __invoke(CommissionInvoice $invoice): int
    {
        $tenant = Tenant::withoutGlobalScopes()->find($invoice->tenant_id);

        if (! $tenant instanceof Tenant) {
            return 0;
        }

        $variant = $invoice->status === CommissionInvoiceStatus::Overdue
            ? CommissionInvoiceNotification::OVERDUE
            : CommissionInvoiceNotification::ISSUED;

        $recipients = $this->notifier->notify(
            $tenant,
            new CommissionInvoiceNotification($invoice, $tenant, $variant),
        );

        $this->audit->record(
            action: AuditAction::CommissionInvoiceResent,
            auditable: $invoice,
            newValues: ['variant' => $variant, 'recipients' => $recipients],
            tenantId: $invoice->tenant_id,
        );

        return $recipients;
    }
}
