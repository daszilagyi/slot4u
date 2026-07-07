<?php

namespace App\Events;

use App\Models\QuoteRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer submitted a quote request (docs/04 §6, SLO-27). M5 listeners
 * subscribe to notify the tenant that a new enquiry has arrived.
 */
class QuoteRequestCreated
{
    use Dispatchable;

    public function __construct(
        public readonly QuoteRequest $quoteRequest,
    ) {}
}
