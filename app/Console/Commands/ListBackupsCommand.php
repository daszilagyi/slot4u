<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupDestination;
use App\Services\Backup\RetentionPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * What is actually in the offsite store (SLO-154).
 *
 * Exists for the first minute of a restore, when the question is "how far back
 * can we go, and is last night's actually there?" — and for the periodic check
 * docs/18 §6 asks an operator to do, because a backup nobody has ever listed is
 * a belief, not a fact.
 */
class ListBackupsCommand extends Command
{
    protected $signature = 'backup:list {--limit=20 : How many of the most recent runs to show}';

    protected $description = 'List the backups present at the offsite destination';

    public function handle(BackupDestination $destination): int
    {
        if (! $destination->isConfigured()) {
            $this->components->warn('No backup destination is configured.');

            return self::SUCCESS;
        }

        $runs = $destination->runs();

        if ($runs === []) {
            $this->components->warn('The destination is empty: '.$destination->describe());

            return self::SUCCESS;
        }

        $this->components->info(count($runs).' backup(s) at '.$destination->describe());

        $limit = max(1, (int) $this->option('limit'));
        $rows = [];

        foreach (array_slice(array_reverse($runs), 0, $limit) as $runId) {
            $files = $destination->filesIn($runId);
            $at = RetentionPolicy::parse($runId);

            $rows[] = [
                $runId,
                $at === null ? '—' : $at->diffForHumans(Carbon::now('UTC')),
                Number::fileSize($destination->sizeOf($runId)),
                // A run without a manifest never finished uploading. Saying so
                // here saves someone downloading a truncated dump first.
                in_array('manifest.json', $files, true) ? 'complete' : 'INCOMPLETE',
                implode(', ', array_diff($files, ['manifest.json'])),
            ];
        }

        $this->table(['Run', 'Age', 'Size', 'State', 'Artifacts'], $rows);

        return self::SUCCESS;
    }
}
