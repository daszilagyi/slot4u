<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Heartbeat;
use App\Services\Monitoring\HealthReport;
use App\Services\Monitoring\Heartbeats;
use App\Services\Monitoring\RunHealthChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * The installation's own watchdog (SLO-153, docs/17-monitoring-es-riasztas.md).
 *
 * Two alerting paths, because they fail in opposite directions:
 *
 *  - **Sentry** carries the detail ("the queue worker last ran 47 minutes ago"),
 *    but it can only speak while this process is running.
 *  - **The dead man's switch** carries no detail at all — it is pinged only when
 *    every check passed, so an external monitor alerts on *silence*. That is the
 *    only signal a host whose cron stopped firing can still send, and it is the
 *    reason the ping is at the end of this command rather than in a cron line of
 *    its own.
 *
 * Both are no-ops without configuration: no DSN, no Sentry event; no heartbeat
 * URL, no outgoing request. Dev and CI stay silent.
 */
class MonitorHealth extends Command
{
    protected $signature = 'monitor:health
        {--beat : Record the scheduler heartbeat (the scheduled run passes this; a manual run must not)}';

    protected $description = 'Check the queue, the scheduler and the failed job table, and alert if anything is wrong';

    public function handle(RunHealthChecks $checks, Heartbeats $heartbeats): int
    {
        $report = $checks();

        foreach ($report->checks as $check) {
            $check->healthy
                ? $this->line('  <fg=green>ok</>   '.$check->name.' — '.$check->message)
                : $this->line('  <fg=red>FAIL</> '.$check->name.' — '.$check->message);
        }

        // After the checks, never before: stamping first would let a manual run
        // refresh the very mark it is supposed to be judging, and a dead
        // scheduler would look alive to the person investigating it.
        if ($this->option('beat')) {
            $heartbeats->beat(Heartbeat::SCHEDULER);
        }

        if ($report->isHealthy()) {
            $this->pingDeadMansSwitch();
            $this->info('All checks passed.');

            return self::SUCCESS;
        }

        $this->reportFailure($report);

        return self::FAILURE;
    }

    /**
     * Named `reportFailure` rather than `alert`: Console\Command already has an
     * `alert()` that prints a banner, and quietly overriding it would be a trap
     * for the next person.
     */
    private function reportFailure(HealthReport $report): void
    {
        // The local log first: it is the one destination that works with no
        // network, no account and no configuration.
        Log::error('Health check failed: '.$report->summary(), $report->context());

        withScope(function (Scope $scope) use ($report): void {
            $scope->setContext('health', $report->context());
            $scope->setTag('monitor', 'health');

            captureMessage('Health check failed: '.$report->summary(), Severity::error());
        });
    }

    /**
     * Silence here is the alert. A failed ping is therefore logged, not retried
     * into success — pretending it worked would suppress the very outage the
     * external monitor exists to catch.
     */
    private function pingDeadMansSwitch(): void
    {
        $url = (string) config('monitoring.heartbeat_url');

        if ($url === '') {
            return;
        }

        try {
            $response = Http::timeout((int) config('monitoring.heartbeat_timeout_seconds'))->get($url);

            if ($response->failed()) {
                Log::warning('Health heartbeat ping was refused', ['status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('Health heartbeat ping failed', ['error' => $e->getMessage()]);
        }
    }
}
