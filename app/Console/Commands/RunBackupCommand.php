<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupSummary;
use App\Services\Backup\RunBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureException;
use function Sentry\withScope;

/**
 * The daily offsite backup (SLO-154, docs/18-backup-es-restore.md).
 *
 * Until this existed the only copy of every tenant's bookings was the hosting
 * account's own snapshot — which is not offsite: lose the account and the backup
 * goes with it.
 *
 * A failure is reported three ways because they fail in different directions:
 * the console (a human ran it), the log (always available), and Sentry (nobody
 * is watching the console at 03:00). The fourth is the absence of a heartbeat,
 * which is what `monitor:health` alerts on when this command stops running at
 * all — the failure mode no error handler here can ever report.
 */
class RunBackupCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Dump the database and the uploaded files and store them offsite';

    public function handle(RunBackup $backup): int
    {
        try {
            $summary = $backup();
        } catch (Throwable $e) {
            $this->report($e);

            return self::FAILURE;
        }

        if (! $summary->performed) {
            $this->components->warn('Backup skipped — '.$summary->skipReason.'.');

            return self::SUCCESS;
        }

        $this->render($summary);

        Log::info('Backup completed', $summary->context());

        return self::SUCCESS;
    }

    private function render(BackupSummary $summary): void
    {
        $this->components->info('Backup '.$summary->runId.' → '.$summary->destination);

        foreach ($summary->artifacts as $name => $bytes) {
            $this->line(sprintf('  <fg=green>ok</>   %s (%s)', $name, Number::fileSize($bytes)));
        }

        if (! $summary->encrypted) {
            // Not a failure — a private bucket is a defensible baseline — but the
            // dump holds every customer's name, email and phone, so nobody should
            // discover this posture by reading the config a year from now.
            $this->components->warn('No BACKUP_PASSPHRASE is set: the dump is stored unencrypted (private bucket only).');
        }

        if ($summary->pruned !== []) {
            $this->line('  <fg=gray>pruned</> '.implode(', ', $summary->pruned));
        }
    }

    private function report(Throwable $e): void
    {
        $this->components->error('Backup failed: '.$e->getMessage());

        Log::error('Backup failed: '.$e->getMessage(), ['exception' => $e]);

        withScope(function (Scope $scope) use ($e): void {
            $scope->setTag('monitor', 'backup');
            $scope->setLevel(Severity::error());

            captureException($e);
        });
    }
}
