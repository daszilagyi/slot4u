<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The offsite store the backups live in (SLO-154).
 *
 * Wraps a Laravel disk so the rest of the subsystem never has to know whether it
 * is talking to S3 or to a local directory — the restore drill (docs/18 §5) runs
 * the identical code path against a local disk, which is the only way the drill
 * proves anything about production.
 */
class BackupDestination
{
    public function isConfigured(): bool
    {
        $config = $this->diskConfig();

        if ($config === null) {
            return false;
        }

        if (($config['driver'] ?? null) !== 's3') {
            return true;
        }

        // An S3 disk with no bucket or no key is not a destination, it is a
        // default. Treating it as configured would mean every run "succeeds"
        // while writing into a client that throws on first contact.
        return ((string) ($config['bucket'] ?? '')) !== ''
            && ((string) ($config['key'] ?? '')) !== '';
    }

    public function describe(): string
    {
        $config = $this->diskConfig() ?? [];
        $driver = (string) ($config['driver'] ?? 'unknown');

        if ($driver !== 's3') {
            return sprintf("disk '%s' (%s) under %s", $this->diskName(), $driver, $this->prefix());
        }

        return sprintf(
            "bucket '%s'%s under %s",
            (string) ($config['bucket'] ?? ''),
            ($config['endpoint'] ?? null) ? ' at '.$config['endpoint'] : '',
            $this->prefix(),
        );
    }

    /**
     * Upload a local file and prove it arrived whole.
     *
     * The size check is not ceremony: an interrupted multipart upload can leave a
     * short object behind, and a short object is a backup that restores to
     * nothing at the worst possible moment.
     */
    public function upload(string $localPath, string $runId, string $name): int
    {
        $expected = (int) @filesize($localPath);
        $remote = $this->pathFor($runId, $name);

        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new BackupFailed("could not open {$localPath} for upload");
        }

        try {
            $written = $this->disk()->writeStream($remote, $stream);
        } catch (Throwable $e) {
            throw new BackupFailed("uploading {$name} failed: ".$e->getMessage(), previous: $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new BackupFailed("uploading {$name} failed");
        }

        $actual = (int) $this->disk()->size($remote);

        if ($actual !== $expected) {
            throw new BackupFailed("{$name} uploaded short: {$actual} bytes at the destination, {$expected} locally");
        }

        return $actual;
    }

    /**
     * Backup run ids present at the destination, oldest first.
     *
     * @return list<string>
     */
    public function runs(): array
    {
        $prefix = $this->prefix();

        $runs = array_map(
            static fn (string $directory): string => basename($directory),
            $this->disk()->directories($prefix),
        );

        sort($runs);

        return $runs;
    }

    public function delete(string $runId): void
    {
        $this->disk()->deleteDirectory($this->prefix().'/'.$runId);
    }

    /**
     * Total bytes stored for one run.
     */
    public function sizeOf(string $runId): int
    {
        $disk = $this->disk();
        $total = 0;

        foreach ($disk->files($this->prefix().'/'.$runId) as $file) {
            $total += (int) $disk->size($file);
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    public function filesIn(string $runId): array
    {
        return array_values(array_map(
            static fn (string $path): string => basename($path),
            $this->disk()->files($this->prefix().'/'.$runId),
        ));
    }

    public function pathFor(string $runId, string $name): string
    {
        return $this->prefix().'/'.$runId.'/'.$name;
    }

    public function prefix(): string
    {
        return trim((string) config('backup.prefix'), '/');
    }

    private function diskName(): string
    {
        return (string) config('backup.disk');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function diskConfig(): ?array
    {
        /** @var array<string, mixed>|null $config */
        $config = config('filesystems.disks.'.$this->diskName());

        return $config;
    }
}
