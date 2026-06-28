<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Moves tenants whose 14-day trial has ended from `trial` to `active` on the
 * free base plan (docs/03 — no downgrade; the commission model has no
 * subscription). Scheduled daily (routes/console.php); idempotent.
 */
class ExpireTrials extends Command
{
    protected $signature = 'tenants:expire-trials';

    protected $description = 'Activate tenants whose trial period has ended.';

    public function handle(): int
    {
        $count = Tenant::query()
            ->where('status', TenantStatus::Trial->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->update(['status' => TenantStatus::Active->value]);

        $this->info("Activated {$count} tenant(s) whose trial ended.");

        return self::SUCCESS;
    }
}
