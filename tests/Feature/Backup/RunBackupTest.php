<?php

use App\Models\Heartbeat;
use App\Services\Backup\BackupDestination;
use App\Services\Backup\BackupFailed;
use App\Services\Backup\DatabaseDump;
use App\Services\Backup\RunBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\FakeDatabaseDump;

/*
|--------------------------------------------------------------------------
| A backup run, end to end (SLO-154)
|--------------------------------------------------------------------------
|
| Everything here is real except mysqldump: tar, gzip and openssl run, and the
| artifacts are streamed to a fake disk through the same BackupDestination
| production uses.
|
*/

beforeEach(function () {
    config()->set('filesystems.disks.backup-test', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/backup-test'), 'throw' => true]);
    Storage::fake('backup-test');

    $this->root = sys_get_temp_dir().'/slot4u-run-'.uniqid();
    mkdir($this->root.'/uploads/public', 0700, true);
    mkdir($this->root.'/work', 0700, true);
    file_put_contents($this->root.'/uploads/public/logo.png', 'logo');

    config()->set('backup.disk', 'backup-test');
    config()->set('backup.prefix', 'backups/testing');
    config()->set('backup.storage_path', $this->root.'/uploads');
    config()->set('backup.working_directory', $this->root.'/work');
    config()->set('backup.passphrase', '');

    $this->dump = new FakeDatabaseDump;
    $this->app->instance(DatabaseDump::class, $this->dump);

    $this->disk = Storage::disk('backup-test');
});

afterEach(function () {
    Process::run(['rm', '-rf', $this->root]);
});

it('uploads the database, the files and a manifest', function () {
    $summary = app(RunBackup::class)();

    expect($summary->performed)->toBeTrue();

    $run = 'backups/testing/'.$summary->runId;

    expect($this->disk->exists($run.'/database.sql.gz'))->toBeTrue()
        ->and($this->disk->exists($run.'/storage.tar.gz'))->toBeTrue()
        ->and($this->disk->exists($run.'/manifest.json'))->toBeTrue();

    $manifest = json_decode($this->disk->get($run.'/manifest.json'), true);

    expect($manifest['run'])->toBe($summary->runId)
        ->and($manifest['encryption'])->toBeNull()
        ->and(collect($manifest['artifacts'])->pluck('name')->all())
        ->toBe(['database.sql.gz', 'storage.tar.gz'])
        // The checksum is what lets an operator prove the download is intact
        // before spending an hour restoring from it.
        ->and($manifest['artifacts'][0]['sha256'])
        ->toBe(hash('sha256', $this->disk->get($run.'/database.sql.gz')));
});

it('records the heartbeat only after the upload finished', function () {
    // `monitor:health` reads this mark. If it were stamped before the upload, a
    // destination that has been rejecting writes for a month would look healthy.
    expect(Heartbeat::query()->find(Heartbeat::BACKUP))->toBeNull();

    app(RunBackup::class)();

    expect(Heartbeat::query()->find(Heartbeat::BACKUP))->not->toBeNull();
});

it('encrypts both artifacts when a passphrase is configured', function () {
    config()->set('backup.passphrase', 'a-long-drill-passphrase');

    $summary = app(RunBackup::class)();
    $run = 'backups/testing/'.$summary->runId;

    expect($summary->encrypted)->toBeTrue()
        ->and($this->disk->exists($run.'/database.sql.gz.enc'))->toBeTrue()
        ->and($this->disk->exists($run.'/storage.tar.gz.enc'))->toBeTrue()
        ->and($this->disk->exists($run.'/database.sql.gz'))->toBeFalse();

    $manifest = json_decode($this->disk->get($run.'/manifest.json'), true);

    // The manifest names the cipher parameters, so the restore does not depend
    // on finding this repository first.
    expect($manifest['encryption'])->toContain('aes-256-cbc')
        ->and($manifest['encryption'])->toContain('pbkdf2');
});

it('does nothing at all when no destination is configured', function () {
    // Same posture as the Sentry DSN (SLO-153): a dev machine makes no outgoing
    // call and reports no problem.
    config()->set('backup.disk', 's3');
    config()->set('filesystems.disks.s3.bucket', '');

    $summary = app(RunBackup::class)();

    expect($summary->performed)->toBeFalse()
        ->and($summary->skipReason)->toContain('no backup destination')
        ->and($this->dump->calls)->toBe(0)
        ->and(Heartbeat::query()->find(Heartbeat::BACKUP))->toBeNull();
});

it('treats an s3 disk with credentials but no bucket as unconfigured', function () {
    config()->set('backup.disk', 's3');
    config()->set('filesystems.disks.s3.key', 'AKIA...');
    config()->set('filesystems.disks.s3.bucket', '');

    expect(app(BackupDestination::class)->isConfigured())->toBeFalse();

    config()->set('filesystems.disks.s3.bucket', 'slot4u-backups');

    expect(app(BackupDestination::class)->isConfigured())->toBeTrue();
});

it('prunes expired runs once the new one is safely stored', function () {
    $old = Carbon::now('UTC')->subYear()->format('Y-m-d_His');
    $recent = Carbon::now('UTC')->subDay()->format('Y-m-d_His');

    $this->disk->put("backups/testing/{$old}/database.sql.gz", 'old');
    $this->disk->put("backups/testing/{$recent}/database.sql.gz", 'recent');

    $summary = app(RunBackup::class)();

    expect($summary->pruned)->toBe([$old])
        ->and($this->disk->exists("backups/testing/{$old}/database.sql.gz"))->toBeFalse()
        ->and($this->disk->exists("backups/testing/{$recent}/database.sql.gz"))->toBeTrue();
});

it('does not prune when the run failed', function () {
    // The safety property that matters most: a run that failed to produce a new
    // backup must not shorten the history it failed to extend.
    $old = Carbon::now('UTC')->subYear()->format('Y-m-d_His');
    $recent = Carbon::now('UTC')->subDay()->format('Y-m-d_His');

    // Two, not one: retention never expires the newest run, so a single stored
    // backup would be protected anyway and the test would pass without proving
    // anything about ordering.
    $this->disk->put("backups/testing/{$old}/database.sql.gz", 'old');
    $this->disk->put("backups/testing/{$recent}/database.sql.gz", 'recent');

    $this->dump->failWith = 'the database dump failed: exit code 2';

    expect(fn () => app(RunBackup::class)())->toThrow(BackupFailed::class);

    expect($this->disk->exists("backups/testing/{$old}/database.sql.gz"))->toBeTrue()
        ->and($this->disk->exists("backups/testing/{$recent}/database.sql.gz"))->toBeTrue()
        ->and(Heartbeat::query()->find(Heartbeat::BACKUP))->toBeNull();
});

it('removes a run it could not finish uploading', function () {
    // A half-uploaded run is worse than no run: it appears in every listing as a
    // backup, and is discovered to be one artifact short during an incident.
    $this->app->bind(BackupDestination::class, fn () => new class extends BackupDestination
    {
        public function upload(string $localPath, string $runId, string $name): int
        {
            if ($name === 'manifest.json') {
                throw new BackupFailed('uploading manifest.json failed');
            }

            return parent::upload($localPath, $runId, $name);
        }
    });

    expect(fn () => app(RunBackup::class)())->toThrow(BackupFailed::class);

    expect($this->disk->directories('backups/testing'))->toBe([]);
});

it('leaves no plaintext dump behind, whether it succeeded or failed', function () {
    // The working directory sits on the same disk as the site. A dump left there
    // is every customer's phone number readable by anything that can read files.
    app(RunBackup::class)();

    expect(glob($this->root.'/work/*'))->toBe([]);

    $this->dump->failWith = 'boom';

    expect(fn () => app(RunBackup::class)())->toThrow(BackupFailed::class);

    expect(glob($this->root.'/work/*'))->toBe([]);
});

it('skips the file archive when nothing has been uploaded', function () {
    config()->set('backup.storage_path', $this->root.'/empty');
    mkdir($this->root.'/empty', 0700, true);

    $summary = app(RunBackup::class)();

    expect(array_keys($summary->artifacts))->toBe(['database.sql.gz', 'manifest.json']);
});
