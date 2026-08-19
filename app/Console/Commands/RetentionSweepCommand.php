<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Privacy\RetentionStep;
use App\Services\Privacy\RetentionSweep;
use Illuminate\Console\Command;

/**
 * Enforces every retention window in `config/privacy.php` (SLO-160, docs/19 §7).
 *
 * Scheduled daily. Thin on purpose: the policy lives in the config and the work
 * in {@see RetentionSweep}, so the same sweep is callable from a future
 * superadmin "run now" button without going through the console.
 */
class RetentionSweepCommand extends Command
{
    protected $signature = 'privacy:retention-sweep';

    protected $description = 'Purge archived tenants past their grace period and enforce every log retention window.';

    public function handle(RetentionSweep $sweep): int
    {
        $steps = $sweep->run();

        foreach ($steps as $step) {
            // A skipped step is a retention duty that is NOT being enforced, so
            // it is warned about rather than listed among the successes.
            $step->wasSkipped()
                ? $this->warn($step->describe())
                : $this->line($step->describe());
        }

        $this->info(sprintf(
            'Retention sweep finished: %d rows affected across %d steps.',
            array_sum(array_map(static fn (RetentionStep $step): int => $step->affected, $steps)),
            count($steps),
        ));

        return self::SUCCESS;
    }
}
