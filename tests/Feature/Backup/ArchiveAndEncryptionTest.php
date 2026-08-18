<?php

use App\Services\Backup\ArtifactEncryptor;
use App\Services\Backup\BackupFailed;
use App\Services\Backup\BackupShell;
use App\Services\Backup\StorageArchive;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Archiving and encryption, with the real tools (SLO-154)
|--------------------------------------------------------------------------
|
| These run tar, gzip and openssl for real. An archive that was never unpacked
| and a ciphertext that was never decrypted prove nothing at all — and the
| decrypt here is byte-for-byte the command docs/18 §4 tells an operator to run
| during an incident, so a change that breaks the runbook breaks this test.
|
*/

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/slot4u-backup-'.uniqid();
    $this->source = $this->root.'/source';
    mkdir($this->source.'/public/logos', 0700, true);

    file_put_contents($this->source.'/public/logos/acme.png', str_repeat('logo-bytes', 100));
    file_put_contents($this->source.'/.gitignore', "*\n");

    $this->shell = new BackupShell('bash', 30);
});

afterEach(function () {
    Process::run(['rm', '-rf', $this->root]);
});

it('archives the uploaded files so they can be unpacked again', function () {
    $target = $this->root.'/storage.tar.gz';

    $bytes = (new StorageArchive($this->shell))->write($this->source, $target);

    expect($bytes)->toBeGreaterThan(0);

    mkdir($this->root.'/unpacked');
    Process::run(['tar', '-xzf', $target, '-C', $this->root.'/unpacked'])->throw();

    expect(file_get_contents($this->root.'/unpacked/public/logos/acme.png'))
        ->toBe(str_repeat('logo-bytes', 100));
});

it('skips the archive when nothing has been uploaded yet', function () {
    // A fresh install holds only Laravel's own .gitignore files. An empty
    // artifact every night is noise that hides the useful ones.
    $empty = $this->root.'/empty';
    mkdir($empty.'/public', 0700, true);
    file_put_contents($empty.'/public/.gitignore', "*\n");

    expect((new StorageArchive($this->shell))->write($empty, $this->root.'/none.tar.gz'))->toBeNull();
});

it('reports a missing tool instead of producing half an archive', function () {
    config()->set('backup.tar_binary', '/nonexistent/tar');

    expect(fn () => (new StorageArchive($this->shell))->write($this->source, $this->root.'/x.tar.gz'))
        ->toThrow(BackupFailed::class, 'the storage archive failed');
});

it('encrypts an artifact so the documented command decrypts it', function () {
    config()->set('backup.passphrase', 'correct horse battery staple');

    $plain = $this->root.'/database.sql.gz';
    file_put_contents($plain, gzencode("-- Dump completed\n", 9));
    $original = file_get_contents($plain);

    $encrypted = (new ArtifactEncryptor($this->shell))->encrypt($plain);

    expect($encrypted)->toBe($plain.'.enc')
        ->and(file_exists($plain))->toBeFalse()
        ->and(file_get_contents($encrypted))->not->toBe($original);

    // Exactly the command in docs/18 §4.
    $decrypted = $this->root.'/roundtrip.sql.gz';
    Process::env(['SLOT4U_BACKUP_PASSPHRASE' => 'correct horse battery staple'])
        ->run([
            'openssl', 'enc', '-d', '-aes-256-cbc', '-md', 'sha256', '-pbkdf2', '-iter', '100000',
            '-salt', '-pass', 'env:SLOT4U_BACKUP_PASSPHRASE', '-in', $encrypted, '-out', $decrypted,
        ])->throw();

    expect(file_get_contents($decrypted))->toBe($original);
});

it('leaves the artifact alone when no passphrase is configured', function () {
    // Supported, and weaker: the private bucket is then the only thing between
    // the dump and a leaked read-only credential. `backup:run` says so out loud.
    config()->set('backup.passphrase', '');

    $plain = $this->root.'/database.sql.gz';
    file_put_contents($plain, 'payload');

    $encryptor = new ArtifactEncryptor($this->shell);

    expect($encryptor->enabled())->toBeFalse()
        ->and($encryptor->encrypt($plain))->toBe($plain)
        ->and(file_get_contents($plain))->toBe('payload');
});

it('never puts the passphrase where ps can read it', function () {
    config()->set('backup.passphrase', 'super-secret');

    Process::fake();

    $plain = $this->root.'/database.sql.gz';
    file_put_contents($plain, 'payload');

    (new ArtifactEncryptor($this->shell))->encrypt($plain);

    Process::assertRan(fn ($process) => ! str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'super-secret',
    ));
});
