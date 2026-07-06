<?php

namespace App\Console\Commands;

use App\Services\Booking\WaitlistService;
use Illuminate\Console\Command;

/**
 * Expires waitlist offers whose window has lapsed and offers the freed place to
 * the next waiter (docs/04 §3, SLO-25). Scheduled hourly (routes/console.php);
 * idempotent — an already-expired offer is skipped.
 */
class ExpireWaitlistOffers extends Command
{
    protected $signature = 'waitlist:expire-offers';

    protected $description = 'Expire lapsed waitlist offers and offer the next in line.';

    public function handle(WaitlistService $waitlist): int
    {
        $count = $waitlist->expireDueOffers();

        $this->info("Expired {$count} waitlist offer(s).");

        return self::SUCCESS;
    }
}
