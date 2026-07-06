<?php

namespace App\Events;

use App\Models\WaitlistEntry;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A waitlisted customer was offered a freed seat (docs/04 §3, SLO-25). M5
 * listeners subscribe to send the "a spot opened up — book within X hours"
 * notification; the offer window is the entry's `offered_until`.
 */
class WaitlistOffered
{
    use Dispatchable;

    public function __construct(
        public readonly WaitlistEntry $entry,
    ) {}
}
