<?php

use App\Models\Heartbeat;
use App\Services\Monitoring\Heartbeats;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Queue liveness mark (SLO-153)
|--------------------------------------------------------------------------
*/

it('stamps the queue heartbeat when a worker loops', function () {
    expect(Heartbeat::query()->find(Heartbeat::QUEUE))->toBeNull();

    Event::dispatch(new Looping('database', 'default'));

    expect(app(Heartbeats::class)->minutesSince(Heartbeat::QUEUE))->toBe(0);
});

it('marks an idle worker run as alive', function () {
    // `Looping` fires even when there is nothing to process, which is the point:
    // the cron worker exits immediately on an empty queue, so keying liveness on
    // processed jobs would raise an alert every quiet night.
    Event::dispatch(new Looping('database', 'default'));

    expect(Heartbeat::query()->find(Heartbeat::QUEUE))->not->toBeNull();
});

it('does not write on every loop of the same worker run', function () {
    $heartbeats = app(Heartbeats::class);

    $heartbeats->beat(Heartbeat::QUEUE);
    $written = Heartbeat::query()->find(Heartbeat::QUEUE)->beat_at;

    Carbon::setTestNow(Carbon::now()->addSeconds(5));
    $heartbeats->beat(Heartbeat::QUEUE);

    // Same instant as the first write: the second call was throttled, so a busy
    // worker cannot turn monitoring into database load.
    expect(Heartbeat::query()->find(Heartbeat::QUEUE)->beat_at->timestamp)
        ->toBe($written->timestamp);

    Carbon::setTestNow();
});

it('reports no elapsed time for a name that has never beaten', function () {
    expect(app(Heartbeats::class)->minutesSince('nothing-here'))->toBeNull();
});
