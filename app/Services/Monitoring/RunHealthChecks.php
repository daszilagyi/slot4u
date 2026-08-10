<?php

namespace App\Services\Monitoring;

use App\Models\Heartbeat;
use App\Services\Backup\BackupDestination;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Judges whether the unsupervised parts of the installation are still alive
 * (SLO-153, docs/17).
 *
 * Separate from the command so the judgement can be tested directly — the AC
 * asks for proof that a dead queue *is detected*, and that proof should not have
 * to go through console output to be believed.
 */
class RunHealthChecks
{
    public function __construct(
        private readonly Heartbeats $heartbeats,
        private readonly BackupDestination $backups,
    ) {}

    public function __invoke(): HealthReport
    {
        $checks = [
            $this->queue(),
            $this->failedJobs(),
            $this->scheduler(),
        ];

        // Appended rather than filtered out: the backup check does not exist at
        // all where backups are not configured, and "absent" is a different
        // report from "present and passing".
        $backup = $this->backup();

        if ($backup !== null) {
            $checks[] = $backup;
        }

        return new HealthReport($checks);
    }

    /**
     * A backup subsystem nobody watches is a backup that stopped six months ago
     * and will be discovered on the day it was needed (SLO-154).
     *
     * Only checked where backups are actually configured: dev machines and CI
     * have no offsite destination and must not be told they are broken. In
     * production the reverse trap — a destination that was never configured at
     * all — is caught by the deploy checklist in docs/18 §2, not here, because
     * this process cannot tell "deliberately off" from "forgotten".
     */
    private function backup(): ?HealthCheck
    {
        if (! $this->backups->isConfigured()) {
            return null;
        }

        $threshold = (int) config('backup.stale_after_hours');
        $minutes = $this->heartbeats->minutesSince(Heartbeat::BACKUP);

        if ($minutes === null) {
            return HealthCheck::failing('backup', 'no backup has ever completed on this host');
        }

        $hours = intdiv($minutes, 60);

        return $hours > $threshold
            ? HealthCheck::failing('backup', "the last successful backup was {$hours} hours ago (limit {$threshold})")
            : HealthCheck::ok('backup', "last successful backup {$hours} hours ago");
    }

    /**
     * On this hosting profile the worker is a cron line. If it stops, the app
     * keeps serving pages while every confirmation email, reminder and invoice
     * silently queues up forever — the failure mode this whole issue exists for.
     */
    private function queue(): HealthCheck
    {
        $threshold = (int) config('monitoring.queue.stale_after_minutes');
        $minutes = $this->heartbeats->minutesSince(Heartbeat::QUEUE);

        if ($minutes === null) {
            // Never seen. On a fresh install that is expected; on a host that has
            // been serving traffic it means the cron line was never installed —
            // which is exactly as broken as a worker that stopped, and far easier
            // to overlook, so it is reported rather than excused.
            return HealthCheck::failing('queue', 'the queue worker has never run on this host');
        }

        return $minutes > $threshold
            ? HealthCheck::failing('queue', "the queue worker last ran {$minutes} minutes ago (limit {$threshold})")
            : HealthCheck::ok('queue', "last run {$minutes} minutes ago");
    }

    /**
     * A failed job is not an abstraction: it is a customer who never got their
     * booking confirmation.
     */
    private function failedJobs(): HealthCheck
    {
        $threshold = (int) config('monitoring.queue.failed_jobs_threshold');

        try {
            $count = (int) DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            return HealthCheck::failing('failed_jobs', 'could not read the failed job table: '.$e->getMessage());
        }

        return $count >= $threshold
            ? HealthCheck::failing('failed_jobs', "{$count} failed job(s) waiting")
            : HealthCheck::ok('failed_jobs', "{$count} failed job(s)");
    }

    /**
     * Only meaningful when someone runs the check by hand: while the scheduler
     * lives it is what runs this sweep, so its own beat is trivially fresh. The
     * signal that catches a *dead* scheduler is the missing external ping — see
     * `monitoring.heartbeat_url`.
     */
    private function scheduler(): HealthCheck
    {
        $threshold = (int) config('monitoring.scheduler.stale_after_minutes');
        $minutes = $this->heartbeats->minutesSince(Heartbeat::SCHEDULER);

        if ($minutes === null) {
            return HealthCheck::failing('scheduler', 'the scheduler has never run on this host');
        }

        return $minutes > $threshold
            ? HealthCheck::failing('scheduler', "the scheduler last ran {$minutes} minutes ago (limit {$threshold})")
            : HealthCheck::ok('scheduler', "last run {$minutes} minutes ago");
    }
}
