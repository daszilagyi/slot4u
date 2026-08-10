<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\Backup\BackupFailed;
use App\Services\Backup\DatabaseDump;

/**
 * Stands in for `mysqldump`, which cannot run against the suite's SQLite
 * database (SLO-154).
 *
 * It writes a real gzip file, so everything downstream — encryption, upload,
 * checksums, the restore drill's own commands — operates on genuine bytes.
 */
final class FakeDatabaseDump extends DatabaseDump
{
    public string $body = "-- MariaDB dump\nCREATE TABLE bookings (id int);\n-- Dump completed on 2026-08-10\n";

    public ?string $failWith = null;

    public int $calls = 0;

    public function __construct()
    {
        parent::__construct(new FakeBackupShell);
    }

    public function write(string $targetPath): int
    {
        $this->calls++;

        if ($this->failWith !== null) {
            throw new BackupFailed($this->failWith);
        }

        file_put_contents($targetPath, gzencode($this->body, 9));

        return (int) filesize($targetPath);
    }
}
