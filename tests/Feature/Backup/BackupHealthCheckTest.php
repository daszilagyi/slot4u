<?php

use App\Models\Heartbeat;
use App\Services\Monitoring\RunHealthChecks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Backup staleness as a health check (SLO-154 × SLO-153)
|--------------------------------------------------------------------------
|
| The failure this guards against is not a backup that errors — that one reports
| itself. It is a backup that quietly stopped running, and is discovered on the
| day it was needed.
|
*/

$checkNamed = fn (string $name) => collect(app(RunHealthChecks::class)()->checks)
    ->firstWhere('name', $name);

it('says nothing about backups where none are configured', function () use ($checkNamed) {
    // Dev machines and CI have no offsite destination and must not be told they
    // are broken.
    config()->set('backup.disk', 's3');
    config()->set('filesystems.disks.s3.bucket', '');

    expect($checkNamed('backup'))->toBeNull();
});

it('fails when a configured host has never completed a backup', function () use ($checkNamed) {
    config()->set('filesystems.disks.backup-test', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/backup-test'), 'throw' => true]);
    Storage::fake('backup-test');
    config()->set('backup.disk', 'backup-test');

    expect($checkNamed('backup')?->healthy)->toBeFalse()
        ->and($checkNamed('backup')?->message)->toContain('has ever completed');
});

it('fails once the last backup is older than the threshold', function () use ($checkNamed) {
    config()->set('filesystems.disks.backup-test', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/backup-test'), 'throw' => true]);
    Storage::fake('backup-test');
    config()->set('backup.disk', 'backup-test');
    config()->set('backup.stale_after_hours', 36);

    Heartbeat::query()->create([
        'name' => Heartbeat::BACKUP,
        'beat_at' => Carbon::now()->subHours(40),
    ]);

    expect($checkNamed('backup')?->healthy)->toBeFalse()
        ->and($checkNamed('backup')?->message)->toContain('40 hours ago');
});

it('passes on a host that backed up last night', function () use ($checkNamed) {
    config()->set('filesystems.disks.backup-test', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/backup-test'), 'throw' => true]);
    Storage::fake('backup-test');
    config()->set('backup.disk', 'backup-test');
    config()->set('backup.stale_after_hours', 36);

    Heartbeat::query()->create([
        'name' => Heartbeat::BACKUP,
        'beat_at' => Carbon::now()->subHours(9),
    ]);

    expect($checkNamed('backup')?->healthy)->toBeTrue();
});
