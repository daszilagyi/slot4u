<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Produces a gzipped `mysqldump` of the application database (SLO-154).
 *
 * The dump is streamed straight into gzip and onto disk — PHP never holds it —
 * and is then read back once to prove three things that a zero exit code does
 * not:
 *
 *  1. the gzip stream decompresses end to end (a truncated upload is the classic
 *     silent backup failure),
 *  2. it ends with mysqldump's own `-- Dump completed` trailer, which the tool
 *     only writes after the last table,
 *  3. it is bigger than a bare header.
 *
 * That verification is the difference between "we take backups" and "we have a
 * backup". It costs one sequential read of a file we just wrote.
 */
class DatabaseDump
{
    /** mysqldump writes this as the final line of a complete dump. */
    private const COMPLETION_MARKER = '-- Dump completed';

    public function __construct(private readonly BackupShell $shell) {}

    /**
     * Write the dump to $targetPath (a `.sql.gz`) and return its size in bytes.
     */
    public function write(string $targetPath): int
    {
        $connection = (string) config('backup.connection') ?: (string) config('database.default');

        /** @var array<string, mixed> $config */
        $config = config('database.connections.'.$connection, []);
        $driver = (string) ($config['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            // Not a silent skip: a host whose database this cannot dump has no
            // backups at all, and that must be loud wherever it happens.
            throw new BackupFailed("cannot dump a '{$driver}' database — only mysql/mariadb are supported (connection '{$connection}')");
        }

        $optionsFile = $targetPath.'.cnf';
        $this->writeOptionsFile($optionsFile, $config);

        try {
            $this->shell->script(
                sprintf(
                    '%s --defaults-extra-file=%s %s %s | %s -9 > %s',
                    escapeshellarg((string) config('backup.mysqldump_binary')),
                    escapeshellarg($optionsFile),
                    implode(' ', array_map('escapeshellarg', $this->dumpOptions())),
                    escapeshellarg((string) ($config['database'] ?? '')),
                    escapeshellarg((string) config('backup.gzip_binary')),
                    escapeshellarg($targetPath),
                ),
                'the database dump failed',
            );
        } finally {
            // The credentials file goes away whatever happened. It is the only
            // artifact of this run that would be worth stealing.
            @unlink($optionsFile);
        }

        return $this->verify($targetPath);
    }

    /**
     * @return list<string>
     */
    private function dumpOptions(): array
    {
        return [
            // Consistent snapshot without locking the booking tables — a lock
            // here would stall every checkout on the site for the duration.
            '--single-transaction',
            // Stream row by row instead of buffering a table into the client's
            // memory, which is the limit that actually binds on shared hosting.
            '--quick',
            // Needs the PROCESS privilege, which a shared-hosting database user
            // does not have. Without this the dump fails outright.
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
        ];
    }

    /**
     * mysqldump reads credentials from this file rather than from the command
     * line, where `ps` would show the database password to every other account
     * on a shared host.
     *
     * @param  array<string, mixed>  $config
     */
    private function writeOptionsFile(string $path, array $config): void
    {
        $lines = ['[client]'];

        foreach ([
            'host' => (string) ($config['host'] ?? ''),
            'port' => (string) ($config['port'] ?? ''),
            'user' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            'socket' => (string) ($config['unix_socket'] ?? ''),
        ] as $key => $value) {
            if ($value === '') {
                continue;
            }

            $lines[] = $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        // 0600 before the content, not after: between a world-readable create and
        // a later chmod there is a window, and on shared hosting the neighbours
        // are strangers.
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new BackupFailed("could not write the database credentials file at {$path}");
        }

        @chmod($path, 0600);
        fwrite($handle, implode("\n", $lines)."\n");
        fclose($handle);
    }

    private function verify(string $path): int
    {
        $bytes = (int) @filesize($path);
        $minimum = (int) config('backup.minimum_dump_bytes');

        if ($bytes < $minimum) {
            throw new BackupFailed("the database dump is only {$bytes} bytes (minimum {$minimum}) — it is almost certainly empty");
        }

        // Decompressing the whole file and keeping only the tail proves the
        // stream is intact without ever holding the dump in memory.
        $tail = $this->shell->script(
            sprintf(
                '%s -dc %s | tail -c 400',
                escapeshellarg((string) config('backup.gzip_binary')),
                escapeshellarg($path),
            ),
            'the database dump could not be read back',
        );

        if (! str_contains($tail, self::COMPLETION_MARKER)) {
            throw new BackupFailed('the database dump has no completion marker — mysqldump stopped before the last table');
        }

        return $bytes;
    }
}
