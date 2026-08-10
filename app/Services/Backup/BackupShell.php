<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Runs the external tools a backup needs (SLO-154).
 *
 * Two entry points, because the two kinds of step have opposite requirements:
 *
 *  - {@see script()} runs a shell pipeline. It exists for `mysqldump | gzip >
 *    file`: redirecting to disk is what keeps a multi-gigabyte dump out of PHP's
 *    memory, and there is no way to express that through an argv array. It runs
 *    under **bash**, not `sh`, purely for `set -o pipefail` — under dash a
 *    mysqldump that dies mid-table still exits 0 through the pipe, and the run
 *    would upload a truncated archive while reporting success.
 *
 *  - {@see command()} runs an argv array with no shell at all, for steps that
 *    take a secret ({@see ArtifactEncryptor} passes the passphrase in the
 *    environment, where `ps` cannot read it).
 *
 * Failures surface as {@see BackupFailed} carrying stderr, because "backup
 * failed" without the reason costs an hour of someone's incident.
 */
class BackupShell
{
    public function __construct(
        private readonly string $shellBinary = 'bash',
        private readonly int $timeout = 1800,
    ) {}

    /**
     * Run a shell pipeline with pipefail, returning stdout.
     *
     * Callers must keep stdout small — anything large belongs in a redirect.
     */
    public function script(string $script, string $failureContext): string
    {
        return $this->execute(
            [$this->shellBinary, '-c', 'set -o pipefail; '.$script],
            [],
            $failureContext,
        );
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function command(array $arguments, array $environment, string $failureContext): string
    {
        return $this->execute($arguments, $environment, $failureContext);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function execute(array $arguments, array $environment, string $failureContext): string
    {
        try {
            $result = Process::timeout($this->timeout)
                ->env($environment)
                ->run($arguments);
        } catch (Throwable $e) {
            // A missing binary, a timeout, a host that forbids proc_open: all of
            // them mean the same thing to the caller, and all of them need the
            // original message to be actionable on shared hosting.
            throw new BackupFailed($failureContext.': '.$e->getMessage(), previous: $e);
        }

        if ($result->failed()) {
            $stderr = trim($result->errorOutput());

            throw new BackupFailed(sprintf(
                '%s: exit code %d%s',
                $failureContext,
                $result->exitCode() ?? -1,
                $stderr === '' ? '' : ' — '.$stderr,
            ));
        }

        return $result->output();
    }
}
