<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Rebuilds every demo tenant from nothing (SLO-183, docs/20 §3.2).
 *
 * `demo:seed --fresh` over all personas, and the entry point the nightly
 * staging reset schedules (SLO-191) — a demo people are invited to click
 * through is a demo people will leave in a mess, and the answer is to make the
 * mess cost nothing.
 *
 * A separate command rather than a flag people must remember, because this is
 * the destructive one and the name should say so at the call site: `demo:reset`
 * in a cron line reads as what it is.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Delete and rebuild every demo tenant (docs/20).';

    public function handle(DemoSeeder $seeder): int
    {
        // Reported before the rebuild, so an operator reading the cron log can
        // see what was there — the rows themselves are gone by the end.
        $existing = $seeder->existingDemoSlugs();

        if ($existing !== []) {
            $this->line('  purging: '.implode(', ', $existing));
        }

        return $this->call('demo:seed', ['--fresh' => true]);
    }
}
