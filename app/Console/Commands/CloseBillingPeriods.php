<?php

namespace App\Console\Commands;

use App\Actions\Commission\GenerateCommissionInvoice;
use App\Enums\BillingPeriodStatus;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Closes each tenant's open billing periods once the grace window has passed and
 * issues the monthly commission invoice (docs/10 §6.5, §7). The grace — the 2nd
 * of the following month in the tenant's timezone (§15.4) — lets a month-end
 * booking's final status (completed/no_show) settle before the period is frozen.
 *
 * Tenant-less/cross-tenant: it walks every open period and closes those whose
 * grace has passed. Idempotent — GenerateCommissionInvoice + the BillingPeriodStatus
 * gate make a re-run a no-op — so it is scheduled hourly and each tenant's month
 * closes within an hour of its local grace instant, whatever timezone it is in.
 */
class CloseBillingPeriods extends Command
{
    protected $signature = 'billing:close-periods';

    protected $description = 'Close tenants\' elapsed billing periods and issue their commission invoices.';

    public function handle(GenerateCommissionInvoice $generate): int
    {
        $now = Carbon::now();
        $closed = 0;

        TenantBillingPeriod::query()
            ->where('status', BillingPeriodStatus::Open->value)
            ->with('tenant')
            ->chunkById(200, function ($periods) use ($generate, $now, &$closed): void {
                foreach ($periods as $period) {
                    $tenant = $period->tenant;

                    // No live tenant (archived/deleted) → nothing to bill.
                    if (! $tenant instanceof Tenant) {
                        continue;
                    }

                    if ($this->graceHasPassed($period->period, $tenant->timezone, $now)) {
                        ($generate)($period->tenant_id, $period->period);
                        $closed++;
                    }
                }
            });

        $this->info("Closed {$closed} billing period(s).");

        return self::SUCCESS;
    }

    /**
     * Whether the period may be closed: now is at/after the 2nd day of the month
     * following the period, at 00:00 in the tenant's timezone (docs/10 §15.4).
     */
    private function graceHasPassed(string $period, string $timezone, Carbon $now): bool
    {
        $closeAt = Carbon::createFromFormat('Y-m-d', $period.'-01', $timezone)
            ->addMonthNoOverflow()
            ->addDay()
            ->startOfDay()
            ->utc();

        return $now->greaterThanOrEqualTo($closeAt);
    }
}
