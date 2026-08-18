<?php

use App\Services\Backup\BackupFailed;
use App\Services\Backup\DatabaseDump;
use Tests\Fixtures\FakeBackupShell;

/*
|--------------------------------------------------------------------------
| The database dump (SLO-154)
|--------------------------------------------------------------------------
|
| mysqldump cannot run against the suite's SQLite database, so the shell is
| faked here and what it was *asked* to do is asserted instead. The flags being
| asserted are not cosmetic: without --single-transaction the dump locks the
| booking tables, without --no-tablespaces it fails outright on shared hosting,
| and without pipefail a dump that dies mid-table uploads as a valid gzip.
|
*/

beforeEach(function () {
    $this->shell = new FakeBackupShell;
    $this->target = sys_get_temp_dir().'/slot4u-dump-test-'.uniqid().'.sql.gz';

    // Never `database.default`: RefreshDatabase holds a transaction on the
    // default connection, and moving it out from under the suite breaks every
    // test after this one.
    config()->set('backup.connection', 'mysql');
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'port' => '3306',
        'database' => 'slot4u_prod',
        'username' => 'slot4u',
        'password' => 'p@ss"word',
    ]);

    // Deliberately incompressible: a dump of 200 identical rows gzips down to
    // less than the minimum-size guard, which is the guard doing its job.
    $rows = array_map(
        fn (int $i): string => "INSERT INTO bookings VALUES ({$i},'".bin2hex(random_bytes(24))."');\n",
        range(1, 200),
    );

    $this->body = implode('', $rows)."-- Dump completed on 2026-08-10\n";

    // Stand in for the shell: write a real gzip where the redirect points, and
    // answer the read-back with the tail of what was written.
    $this->shell->onScript = function (string $script): string {
        if (str_contains($script, 'mysqldump')) {
            $this->capturedOptionsFile = file_get_contents($this->target.'.cnf');
            $this->optionsFilePerms = substr(sprintf('%o', fileperms($this->target.'.cnf')), -4);
            file_put_contents($this->target, gzencode($this->body, 9));

            return '';
        }

        return substr($this->body, -400);
    };
});

afterEach(function () {
    @unlink($this->target);
    @unlink($this->target.'.cnf');
});

it('dumps with the flags that make it safe on shared hosting', function () {
    $bytes = (new DatabaseDump($this->shell))->write($this->target);

    $script = $this->shell->scriptContaining('mysqldump');

    expect($bytes)->toBeGreaterThan(0)
        ->and($script)->toContain('--single-transaction')
        ->and($script)->toContain('--quick')
        ->and($script)->toContain('--no-tablespaces')
        ->and($script)->toContain("'slot4u_prod'")
        ->and($script)->toContain('| '."'gzip' -9 >");
});

it('keeps the database password out of the command line', function () {
    // argv is world-readable through `ps`. On a shared host the neighbours are
    // strangers.
    (new DatabaseDump($this->shell))->write($this->target);

    expect($this->shell->scriptContaining('mysqldump'))->not->toContain('p@ss')
        ->and($this->capturedOptionsFile)->toContain('password="p@ss\"word"')
        ->and($this->optionsFilePerms)->toBe('0600');
});

it('deletes the credentials file even when the dump fails', function () {
    $this->shell->onScript = function (string $script): string {
        if (str_contains($script, 'mysqldump')) {
            throw new BackupFailed('the database dump failed: exit code 2');
        }

        return '';
    };

    expect(fn () => (new DatabaseDump($this->shell))->write($this->target))
        ->toThrow(BackupFailed::class);

    expect(file_exists($this->target.'.cnf'))->toBeFalse();
});

it('refuses to pretend it can dump a non-mysql database', function () {
    config()->set('backup.connection', 'sqlite');

    expect(fn () => (new DatabaseDump($this->shell))->write($this->target))
        ->toThrow(BackupFailed::class, "cannot dump a 'sqlite' database");
});

it('rejects a dump that is barely bigger than its own header', function () {
    // mysqldump can exit 0 after writing nothing but a header when a privilege
    // check fails mid-run. An empty archive that uploads cleanly is the worst
    // outcome available: green logs, no data.
    $this->body = "-- MariaDB dump\n-- Dump completed\n";

    expect(fn () => (new DatabaseDump($this->shell))->write($this->target))
        ->toThrow(BackupFailed::class, 'almost certainly empty');
});

it('rejects a dump that stopped before the last table', function () {
    // Big enough to clear the size guard: this is the failure the size guard
    // cannot see — plenty of rows, and then nothing.
    $this->body = str_replace("-- Dump completed on 2026-08-10\n", '', $this->body);

    expect(fn () => (new DatabaseDump($this->shell))->write($this->target))
        ->toThrow(BackupFailed::class, 'no completion marker');
});

it('reads the archive back rather than trusting the exit code', function () {
    (new DatabaseDump($this->shell))->write($this->target);

    // Decompressing the whole file is what proves the gzip stream is intact;
    // the tail is only how the completeness marker is found.
    expect($this->shell->scriptContaining('-dc'))->toContain('| tail -c 400');
});
