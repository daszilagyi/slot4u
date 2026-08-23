<?php

use App\Models\Heartbeat;
use App\Services\Monitoring\Heartbeats;
use App\Services\Monitoring\RunHealthChecks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Watchdog (SLO-153)
|--------------------------------------------------------------------------
|
| The AC asks for proof that a dead queue is *detected* — on this hosting
| profile the worker is a cron line with nothing supervising it, so silent
| death is the realistic failure, not a crash.
|
*/

beforeEach(function () {
    config()->set('monitoring.queue.stale_after_minutes', 15);
    config()->set('monitoring.scheduler.stale_after_minutes', 15);
    config()->set('monitoring.queue.failed_jobs_threshold', 1);
    config()->set('monitoring.heartbeat_url', 'https://hc.example.com/ping/abc');
    // Pinned high so the log-size check (SLO-175) cannot make the "everything
    // passed" assertions below depend on how much this particular machine has
    // logged. Its own two tests set the limit deliberately.
    config()->set('monitoring.logs.max_megabytes', 4096);
});

/** Put both cron-driven parts in a healthy state. */
function beatEverything(?Carbon $at = null): void
{
    $at ??= Carbon::now();

    foreach ([Heartbeat::QUEUE, Heartbeat::SCHEDULER] as $name) {
        Heartbeat::query()->updateOrCreate(['name' => $name], ['beat_at' => $at]);
    }
}

it('detects a queue worker that stopped running', function () {
    beatEverything();
    Heartbeat::query()->where('name', Heartbeat::QUEUE)->update(['beat_at' => Carbon::now()->subMinutes(47)]);

    $report = app(RunHealthChecks::class)();

    expect($report->isHealthy())->toBeFalse();
    expect($report->summary())->toContain('queue')->toContain('47 minutes ago');
});

it('treats a queue that has never run as broken, not as fine', function () {
    // The cron line was never installed. Everything looks normal until the first
    // customer does not get their confirmation.
    Heartbeat::query()->updateOrCreate(['name' => Heartbeat::SCHEDULER], ['beat_at' => Carbon::now()]);

    $report = app(RunHealthChecks::class)();

    expect($report->isHealthy())->toBeFalse();
    expect($report->summary())->toContain('never run');
});

it('accepts a queue that ran within the threshold', function () {
    beatEverything(Carbon::now()->subMinutes(3));

    expect(app(RunHealthChecks::class)()->isHealthy())->toBeTrue();
});

it('detects failed jobs waiting in the table', function () {
    beatEverything();
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => Carbon::now(),
    ]);

    $report = app(RunHealthChecks::class)();

    expect($report->isHealthy())->toBeFalse();
    expect($report->summary())->toContain('1 failed job');
});

it('exits non-zero and reports when something is wrong', function () {
    Http::fake();
    Log::spy();
    beatEverything(Carbon::now()->subHours(2));

    $this->artisan('monitor:health')->assertExitCode(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'Health check failed'));
});

it('does not ping the dead man switch while anything is failing', function () {
    // The whole point: silence is the alert. A ping sent during an outage would
    // tell the external monitor everything is fine.
    Http::fake();
    beatEverything(Carbon::now()->subHours(2));

    $this->artisan('monitor:health')->assertExitCode(1);

    Http::assertNothingSent();
});

it('pings the dead man switch when every check passed', function () {
    Http::fake();
    beatEverything();

    $this->artisan('monitor:health')->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://hc.example.com/ping/abc');
});

it('makes no outgoing call when no heartbeat url is configured', function () {
    Http::fake();
    config()->set('monitoring.heartbeat_url', '');
    beatEverything();

    $this->artisan('monitor:health')->assertExitCode(0);

    Http::assertNothingSent();
});

it('keeps the run green when the heartbeat monitor itself is down', function () {
    // A refused ping means the monitor is unreachable, not that the app is sick;
    // failing the command would raise an alert about the alerting.
    Http::fake(fn () => Http::response('nope', 500));
    beatEverything();

    $this->artisan('monitor:health')->assertExitCode(0);
});

it('records the scheduler heartbeat only when told to', function () {
    Http::fake();
    beatEverything(Carbon::now()->subMinutes(10));

    $this->artisan('monitor:health')->assertExitCode(0);
    expect(app(Heartbeats::class)->minutesSince(Heartbeat::SCHEDULER))->toBe(10);

    $this->artisan('monitor:health --beat')->assertExitCode(0);
    expect(app(Heartbeats::class)->minutesSince(Heartbeat::SCHEDULER))->toBe(0);
});

it('lets an operator see a dead scheduler instead of refreshing it', function () {
    // A manual run must not stamp the mark it is judging — otherwise the person
    // investigating a silent host is told the scheduler is alive.
    Http::fake();
    beatEverything();
    Heartbeat::query()->where('name', Heartbeat::SCHEDULER)->update(['beat_at' => Carbon::now()->subHour()]);

    $report = app(RunHealthChecks::class)();

    expect($report->isHealthy())->toBeFalse();
    expect($report->summary())->toContain('scheduler');

    $this->artisan('monitor:health')->assertExitCode(1);
    expect(app(Heartbeats::class)->minutesSince(Heartbeat::SCHEDULER))->toBe(60);
});

// --- Log directory size (SLO-175) ---

/**
 * A real file in the real log directory.
 *
 * The check globs and stats, so a faked disk would test a different code path
 * than the one that runs in production. Two megabytes is cheap to write and
 * unambiguous against a one-megabyte limit.
 */
function writeLogFile(int $megabytes): string
{
    $path = storage_path('logs/slo175-size-probe.log');
    file_put_contents($path, str_repeat('x', $megabytes * 1024 * 1024));

    return $path;
}

afterEach(function () {
    $probe = storage_path('logs/slo175-size-probe.log');

    if (is_file($probe)) {
        unlink($probe);
    }
});

it('reports a log directory that has outgrown its limit', function () {
    // Rotation bounds the logs by TIME — fourteen files, then the oldest goes.
    // This is the other axis: one afternoon of a stack trace in a loop outgrows
    // a fortnight of ordinary traffic, and rotation keeps all fourteen days of it.
    beatEverything();
    writeLogFile(2);
    config()->set('monitoring.logs.max_megabytes', 1);

    $report = app(RunHealthChecks::class)();
    $logs = collect($report->checks)->firstWhere('name', 'logs');

    expect($logs->healthy)->toBeFalse()
        ->and($logs->message)->toContain('limit 1');
});

it('is content with a log directory inside its limit', function () {
    beatEverything();
    writeLogFile(2);
    config()->set('monitoring.logs.max_megabytes', 512);

    $logs = collect(app(RunHealthChecks::class)()->checks)->firstWhere('name', 'logs');

    expect($logs->healthy)->toBeTrue();
});

it('fails the whole sweep on a full log directory, so somebody is told', function () {
    // The point of making this a health check rather than a config setting: when
    // the disk fills on the shared host, the booking flow, the queue worker and
    // the nightly backup stop at the same moment.
    beatEverything();
    writeLogFile(2);
    config()->set('monitoring.logs.max_megabytes', 1);
    Http::fake();

    test()->artisan('monitor:health')->assertFailed();

    // And the dead man's switch is NOT pinged — the absence of the ping is what
    // the external monitor alerts on.
    Http::assertNothingSent();
});
