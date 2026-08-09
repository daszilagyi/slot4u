<?php

namespace App\Services\Monitoring;

use App\Models\Heartbeat;
use Illuminate\Support\Carbon;

/**
 * Records and reads the liveness marks of the cron-driven parts (SLO-153).
 *
 * One class so that "what counts as stale" is decided in exactly one place: the
 * worker that writes the mark and the health check that judges it must not be
 * able to disagree.
 */
class Heartbeats
{
    /**
     * How often a beat is actually written, at most. The queue worker loops many
     * times per run; the interesting fact is "this run happened", not how busy it
     * was, and a write per loop would turn monitoring into load.
     */
    private const THROTTLE_SECONDS = 30;

    /** @var array<string, float> in-process guard, per worker run */
    private array $lastWrittenAt = [];

    public function beat(string $name): void
    {
        $now = microtime(true);
        $last = $this->lastWrittenAt[$name] ?? null;

        if ($last !== null && ($now - $last) < self::THROTTLE_SECONDS) {
            return;
        }

        $this->lastWrittenAt[$name] = $now;

        Heartbeat::query()->updateOrCreate(
            ['name' => $name],
            ['beat_at' => Carbon::now()],
        );
    }

    public function lastBeat(string $name): ?Carbon
    {
        $heartbeat = Heartbeat::query()->find($name);

        return $heartbeat?->beat_at;
    }

    /**
     * Minutes since the last beat, or null when there has never been one.
     *
     * Null is not zero and not infinity: a host that has never run the worker
     * (a fresh install, a dev machine) is a different situation from one whose
     * worker died, and the caller gets to decide what to do about it.
     */
    public function minutesSince(string $name): ?int
    {
        $last = $this->lastBeat($name);

        return $last === null ? null : (int) $last->diffInMinutes(Carbon::now(), absolute: true);
    }
}
