<?php

namespace App\Events;

use App\Listeners\SendMessageNotifications;
use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was added to a tenant ↔ customer thread (SLO-36).
 * {@see SendMessageNotifications} tells the other side.
 */
class MessageSent
{
    use Dispatchable;

    public function __construct(
        public readonly Message $message,
    ) {}
}
