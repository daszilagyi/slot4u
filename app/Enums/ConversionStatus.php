<?php

namespace App\Enums;

/**
 * Where a server-side conversion event stands (SLO-173).
 *
 * `Pending` is not "queued": it is "the visitor consented and the booking
 * exists, but it is not a sale yet". Most of a busy tenant's rows sit here for
 * minutes, and a few — the bookings that lapse unpaid or get rejected — sit here
 * forever and are eventually pruned. That is correct: nothing was sold.
 */
enum ConversionStatus: string
{
    case Pending = 'pending';

    /**
     * Claimed by a listener and handed to the queue.
     *
     * The state exists so that claiming is an atomic `pending → queued` update:
     * whoever wins the update owns the send, and a second caller finds nothing to
     * claim. Without it, two invocations of the same transition would each
     * dispatch a job, and Meta would be told about one sale twice.
     *
     * ⚠️ That is not hypothetical. Laravel's event discovery registers every
     * class in `app/Listeners` on top of this app's explicit `Event::listen`
     * calls, so each domain listener currently runs TWICE per event (SLO-174).
     * The other listeners happen to be idempotent and hide it; this one is
     * idempotent on purpose.
     */
    case Queued = 'queued';

    case Sent = 'sent';
    case Failed = 'failed';
}
