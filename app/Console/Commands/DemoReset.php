<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * Rebuilds every demo tenant from nothing (SLO-183, docs/20 §3.2).
 *
 * `demo:seed --fresh` over all personas, and the entry point the nightly
 * reset schedules (SLO-191) — a demo people are invited to click through is a
 * demo people will leave in a mess, and the answer is to make the mess cost
 * nothing.
 *
 * A separate command rather than a flag people must remember, because this is
 * the destructive one and the name should say so at the call site: `demo:reset`
 * in a cron line reads as what it is.
 *
 * ## Why this one reports for itself (SLO-191)
 *
 * Every other scheduled command here can fail quietly and be caught by the next
 * run. This one cannot. A half-built demo does not look broken — it looks like a
 * product with missing features, and the person who finds out is a prospect on a
 * sales call. {@see DemoSeeder::run()} wraps each persona in its own
 * transaction, so a failure part-way leaves the personas before it built and the
 * ones after it absent, with nothing on screen to say so.
 *
 * Hence: the local log always, Sentry when it is configured, and a non-zero exit
 * so the cron mailer has something to send too.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Delete and rebuild every demo tenant (docs/20).';

    public function handle(DemoSeeder $seeder): int
    {
        // Read before the rebuild, so an operator reading the cron log can see
        // what was there — the rows themselves are gone by the end.
        $existing = $seeder->existingDemoSlugs();

        if ($existing !== []) {
            $this->line('  purging: '.implode(', ', $existing));
        }

        $startedAt = microtime(true);

        try {
            $exit = $this->call('demo:seed', ['--fresh' => true]);
        } catch (Throwable $e) {
            $this->reportFailure($e->getMessage(), $existing, $startedAt, $e);

            return self::FAILURE;
        }

        if ($exit !== self::SUCCESS) {
            // A refusal rather than a crash — a slug owned by a real tenant, say.
            // `demo:seed` has already printed why; what this adds is that the
            // *nightly* run is the thing that failed, which is the part nobody
            // is watching.
            $this->reportFailure("demo:seed exited with code {$exit}", $existing, $startedAt);

            return self::FAILURE;
        }

        $seconds = $this->elapsed($startedAt);

        // Logged on success too, and deliberately: this is the only record that
        // the reset is still running at all, and the only place its runtime is
        // visible as it grows with the personas (docs/20 §3.2).
        Log::info('Nightly demo reset rebuilt the demo tenants', [
            'tenants' => $existing,
            'seconds' => $seconds,
        ]);

        $this->info(sprintf('Demo reset finished in %ss.', $seconds));

        return self::SUCCESS;
    }

    /**
     * Named `reportFailure` rather than `alert`, because Console\Command already
     * has an `alert()` that prints a banner and overriding it would be a trap
     * for the next person. Same reasoning, and same shape, as MonitorHealth.
     *
     * @param  list<string>  $tenants  the demo tenants found before the rebuild
     */
    private function reportFailure(string $reason, array $tenants, float $startedAt, ?Throwable $e = null): void
    {
        $context = [
            'reason' => $reason,
            // What was there when the run began. Deliberately not re-queried
            // after the failure: whatever just broke may well be the database,
            // and a handler that throws is a handler that reports nothing.
            'tenants' => $tenants,
            'seconds' => $this->elapsed($startedAt),
        ];

        // The local log first: the one destination that works with no network,
        // no account and no configuration.
        Log::error('Nightly demo reset failed: '.$reason, $context);

        withScope(function (Scope $scope) use ($context, $reason, $e): void {
            $scope->setContext('demo_reset', $context);
            $scope->setTag('monitor', 'demo-reset');

            $e !== null
                ? captureException($e)
                : captureMessage('Nightly demo reset failed: '.$reason, Severity::error());
        });
    }

    private function elapsed(float $startedAt): float
    {
        return round(microtime(true) - $startedAt, 1);
    }
}
