<?php

namespace App\Listeners\Monitoring;

use App\Models\Heartbeat;
use App\Services\Monitoring\Heartbeats;
use Illuminate\Queue\Events\Looping;

/**
 * Stamps "the queue worker ran" on every worker loop (SLO-153).
 *
 * `Looping` rather than `JobProcessed`, deliberately: on this profile the worker
 * is a cron line that exits as soon as the queue is empty, so an idle hour would
 * produce no processed job at all. The question being monitored is whether the
 * *cron* still fires, and an empty run answers it just as well as a busy one —
 * keying on processed jobs would raise an alert every quiet night.
 */
class RecordQueueHeartbeat
{
    public function __construct(private readonly Heartbeats $heartbeats) {}

    public function handle(Looping $event): void
    {
        $this->heartbeats->beat(Heartbeat::QUEUE);
    }
}
