<?php

namespace App\Console\Commands;

use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Notifications\CommissionInvoiceNotification;
use App\Services\Notification\TenantAdminNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily dunning for unpaid commission invoices (docs/10 §6.6, §7): flips invoices
 * past their due date to `overdue` and reminds the tenant admins, then suspends a
 * tenant whose invoice has stayed overdue beyond the grace window (§15.3 — 14 days
 * after the 8-day due date). A suspended tenant's public surface is already blocked
 * by EnsureTenantActive; paying up (MarkCommissionInvoicePaid) reactivates it.
 */
class DunningSweep extends Command
{
    /** Days an invoice may stay overdue before the tenant is suspended (docs/10 §15.3). */
    public const int GRACE_DAYS = 14;

    protected $signature = 'billing:dunning-sweep';

    protected $description = 'Remind tenants of overdue commission invoices and suspend after the grace period.';

    public function handle(TenantAdminNotifier $notifier, ChangeTenantStatus $changeStatus): int
    {
        $now = Carbon::now();

        $reminded = $this->flagOverdue($now, $notifier);
        $suspended = $this->suspendPastGrace($now, $changeStatus, $notifier);

        $this->info("Dunning: {$reminded} reminded, {$suspended} suspended.");

        return self::SUCCESS;
    }

    /**
     * Move issued invoices past their due date to `overdue`, mirror the period, and
     * send the tenant a dunning reminder.
     */
    private function flagOverdue(Carbon $now, TenantAdminNotifier $notifier): int
    {
        $reminded = 0;

        $invoices = CommissionInvoice::query()
            ->where('status', CommissionInvoiceStatus::Issued->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->with('tenant')
            ->get();

        foreach ($invoices as $invoice) {
            $invoice->status = CommissionInvoiceStatus::Overdue;
            $invoice->save();

            TenantBillingPeriod::withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('period', $invoice->period)
                ->update(['status' => BillingPeriodStatus::Overdue->value]);

            $tenant = $invoice->tenant;
            if ($tenant instanceof Tenant) {
                $notifier->notify($tenant, new CommissionInvoiceNotification($invoice, $tenant, CommissionInvoiceNotification::OVERDUE));
                $reminded++;
            }
        }

        return $reminded;
    }

    /**
     * Suspend tenants whose invoice has been overdue beyond the grace window, and
     * email the tenant admins the suspension notice (docs/10 §11).
     */
    private function suspendPastGrace(Carbon $now, ChangeTenantStatus $changeStatus, TenantAdminNotifier $notifier): int
    {
        $suspended = 0;
        $cutoff = $now->copy()->subDays(self::GRACE_DAYS);

        $invoices = CommissionInvoice::query()
            ->where('status', CommissionInvoiceStatus::Overdue->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $cutoff)
            ->with('tenant')
            ->get();

        foreach ($invoices as $invoice) {
            $tenant = $invoice->tenant;

            // Only suspend a live (trial/active) tenant; leave already-suspended
            // and archived tenants untouched.
            if ($tenant instanceof Tenant && $tenant->status->isOperational()) {
                ($changeStatus)($tenant, TenantStatus::Suspended);
                $notifier->notify($tenant, new CommissionInvoiceNotification($invoice, $tenant, CommissionInvoiceNotification::SUSPENDED));
                $suspended++;
            }
        }

        return $suspended;
    }
}
