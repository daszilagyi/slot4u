<?php

use App\Services\Backup\DatabaseDump;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\FakeDatabaseDump;

/*
|--------------------------------------------------------------------------
| The backup commands (SLO-154)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('filesystems.disks.backup-test', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/backup-test'), 'throw' => true]);
    Storage::fake('backup-test');

    $this->root = sys_get_temp_dir().'/slot4u-cmd-'.uniqid();
    mkdir($this->root.'/work', 0700, true);
    mkdir($this->root.'/uploads', 0700, true);

    config()->set('backup.disk', 'backup-test');
    config()->set('backup.prefix', 'backups/testing');
    config()->set('backup.storage_path', $this->root.'/uploads');
    config()->set('backup.working_directory', $this->root.'/work');
    config()->set('backup.passphrase', '');

    $this->dump = new FakeDatabaseDump;
    $this->app->instance(DatabaseDump::class, $this->dump);
});

afterEach(function () {
    Process::run(['rm', '-rf', $this->root]);
});

it('reports what it stored', function () {
    $this->artisan('backup:run')
        ->expectsOutputToContain('database.sql.gz')
        ->assertSuccessful();
});

it('warns out loud when the dump is stored unencrypted', function () {
    // A private bucket is a defensible baseline, but nobody should discover this
    // posture a year later by reading the config.
    $this->artisan('backup:run')
        ->expectsOutputToContain('unencrypted')
        ->assertSuccessful();
});

it('says nothing alarming where backups are not configured', function () {
    config()->set('backup.disk', 's3');
    config()->set('filesystems.disks.s3.bucket', '');

    $this->artisan('backup:run')
        ->expectsOutputToContain('Backup skipped')
        ->assertSuccessful();
});

it('fails loudly and logs when the backup breaks', function () {
    Log::spy();

    $this->dump->failWith = 'the database dump failed: exit code 2';

    $this->artisan('backup:run')->assertFailed();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'the database dump failed'))
        ->once();
});

it('lists what is actually in the destination', function () {
    $complete = Carbon::now('UTC')->subDay()->format('Y-m-d_His');
    $partial = Carbon::now('UTC')->subDays(2)->format('Y-m-d_His');

    Storage::disk('backup-test')->put("backups/testing/{$complete}/database.sql.gz", 'x');
    Storage::disk('backup-test')->put("backups/testing/{$complete}/manifest.json", '{}');
    Storage::disk('backup-test')->put("backups/testing/{$partial}/database.sql.gz", 'x');

    // A run with no manifest never finished uploading, and saying so here saves
    // someone downloading a truncated dump first.
    $this->artisan('backup:list')
        ->expectsOutputToContain($complete)
        ->expectsOutputToContain('INCOMPLETE')
        ->assertSuccessful();
});
