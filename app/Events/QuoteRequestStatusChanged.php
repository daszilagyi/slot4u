<?php

namespace App\Events;

use App\Enums\QuoteRequestStatus;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A quote request moved to a new status (docs/04 §6, SLO-27). M5 listeners
 * subscribe to notify the customer (e.g. "your quote is ready" on `quoted`).
 */
class QuoteRequestStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly QuoteRequest $quoteRequest,
        public readonly QuoteRequestStatus $from,
        public readonly QuoteRequestStatus $to,
        public readonly ?User $actor = null,
    ) {}
}
