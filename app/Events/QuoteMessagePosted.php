<?php

namespace App\Events;

use App\Models\QuoteRequestMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was appended to a quote request thread (docs/04 §6, SLO-27). M5
 * listeners subscribe to notify the other party.
 */
class QuoteMessagePosted
{
    use Dispatchable;

    public function __construct(
        public readonly QuoteRequestMessage $message,
    ) {}
}
