<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Heartbeat;
use App\Services\Monitoring\Heartbeats;
use App\Support\Release;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One backup run, end to end (SLO-154, docs/18-backup-es-restore.md).
 *
 * Order matters more than anything else in this class:
 *
 *  1. build and **verify** each artifact locally,
 *  2. upload it and check the byte count at the far end,
 *  3. write the manifest **last** — a run directory containing `manifest.json`
 *     is a complete run, and that is the one thing a person restoring at 3am can
 *     tell at a glance,
 *  4. only then stamp the heartbeat and prune.
 *
 * Pruning after the upload rather than before is the safety property that
 * matters most: a run that failed to produce a new backup can never shorten the
 * history it failed to extend.
 */
class RunBackup
{
    public function __construct(
        private readonly DatabaseDump $database,
        private readonly StorageArchive $storage,
        private readonly ArtifactEncryptor $encryptor,
        private readonly BackupDestination $destination,
        private readonly Heartbeats $heartbeats,
    ) {}

    public function __invoke(): BackupSummary
    {
        if (! $this->destination->isConfigured()) {
            // Same posture as the Sentry DSN and the heartbeat URL (SLO-153):
            // no credentials, no outgoing call, no noise on a dev machine.
            return BackupSummary::skipped('no backup destination is configured');
        }

        $runId = Carbon::now('UTC')->format(RetentionPolicy::RUN_ID_FORMAT);
        $workingDirectory = $this->prepareWorkingDirectory();

        /** @var array<string, int> $uploaded */
        $uploaded = [];
        /** @var list<array<string, mixed>> $manifestArtifacts */
        $manifestArtifacts = [];
        $uploadStarted = false;

        try {
            foreach ($this->buildArtifacts($workingDirectory) as $name => $localPath) {
                $localPath = $this->encryptor->encrypt($localPath);
                $name = basename($localPath);

                $manifestArtifacts[] = [
                    'name' => $name,
                    'bytes' => (int) @filesize($localPath),
                    'sha256' => hash_file('sha256', $localPath) ?: null,
                ];

                $uploadStarted = true;
                $uploaded[$name] = $this->destination->upload($localPath, $runId, $name);
            }

            $manifestPath = $this->writeManifest($workingDirectory, $runId, $manifestArtifacts);
            $uploaded['manifest.json'] = $this->destination->upload($manifestPath, $runId, 'manifest.json');
        } catch (Throwable $e) {
            // A half-uploaded run is worse than no run: it looks like a backup in
            // every listing. Best effort — if the destination is unreachable this
            // will fail too, and the original error is the one worth keeping.
            if ($uploadStarted) {
                try {
                    $this->destination->delete($runId);
                } catch (Throwable) {
                    // ignored on purpose
                }
            }

            throw $e;
        } finally {
            $this->clean($workingDirectory);
        }

        $this->heartbeats->beat(Heartbeat::BACKUP);

        return BackupSummary::performed(
            $runId,
            $this->destination->describe(),
            $this->encryptor->enabled(),
            $uploaded,
            $this->prune(),
        );
    }

    /**
     * @return array<string, string> artifact name => local path
     */
    private function buildArtifacts(string $workingDirectory): array
    {
        $artifacts = [];

        $dump = $workingDirectory.'/database.sql.gz';
        $this->database->write($dump);
        $artifacts['database.sql.gz'] = $dump;

        if ((bool) config('backup.include_storage')) {
            $archive = $workingDirectory.'/storage.tar.gz';

            if ($this->storage->write((string) config('backup.storage_path'), $archive) !== null) {
                $artifacts['storage.tar.gz'] = $archive;
            }
        }

        return $artifacts;
    }

    /**
     * The manifest answers the questions a restore actually asks: which code
     * version wrote this schema, is the payload encrypted, and did the bytes I
     * downloaded survive the trip.
     *
     * It deliberately holds no secret — the destination is private, but a
     * manifest is the one file someone will paste into a chat while debugging.
     *
     * @param  list<array<string, mixed>>  $artifacts
     */
    private function writeManifest(string $workingDirectory, string $runId, array $artifacts): string
    {
        $connection = (string) config('backup.connection') ?: (string) config('database.default');

        $manifest = [
            'run' => $runId,
            'created_at' => Carbon::now('UTC')->toIso8601String(),
            'app_env' => (string) config('app.env'),
            'app_url' => (string) config('app.url'),
            'release' => [
                'ref' => Release::current(),
                'commit' => Release::commit(),
            ],
            'database' => [
                'connection' => $connection,
                'name' => (string) config('database.connections.'.$connection.'.database'),
            ],
            'encryption' => $this->encryptor->enabled()
                ? 'openssl enc -aes-256-cbc -md sha256 -pbkdf2 -iter 100000 -salt'
                : null,
            'artifacts' => $artifacts,
            'restore' => 'docs/18-backup-es-restore.md',
        ];

        $path = $workingDirectory.'/manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @return list<string>
     */
    private function prune(): array
    {
        $expired = RetentionPolicy::fromConfig()->expired($this->destination->runs(), Carbon::now('UTC'));

        foreach ($expired as $runId) {
            $this->destination->delete($runId);
        }

        return $expired;
    }

    private function prepareWorkingDirectory(): string
    {
        $directory = (string) config('backup.working_directory');

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new BackupFailed("could not create the backup working directory at {$directory}");
        }

        // A previous crash can leave a plaintext dump behind. Clearing on the way
        // in as well as on the way out means it never outlives one run.
        $this->clean($directory);

        return $directory;
    }

    private function clean(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
