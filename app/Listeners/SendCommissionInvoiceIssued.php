<?php

namespace App\Listeners;

use App\Events\CommissionInvoiceIssued;
use App\Models\Tenant;
use App\Notifications\CommissionInvoiceNotification;
use App\Services\Notification\TenantAdminNotifier;

/**
 * Emails the tenant's admins their freshly issued monthly commission invoice
 * (docs/10 §6.5). Resolves the tenant off the invoice so it works from the
 * period-close command, which runs without an ambient tenant.
 */
class SendCommissionInvoiceIssued
{
    public function __construct(private readonly TenantAdminNotifier $notifier) {}

    public function handle(CommissionInvoiceIssued $event): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($event->invoice->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $this->notifier->notify($tenant, new CommissionInvoiceNotification($event->invoice, $tenant));
    }
}
