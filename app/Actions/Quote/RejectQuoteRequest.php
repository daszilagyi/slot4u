<?php

namespace App\Actions\Quote;

use App\Enums\QuoteRequestStatus;
use App\Models\QuoteRequest;
use App\Models\User;

/**
 * Rejects a quote request (docs/04 §6, SLO-27): a terminal decline reachable from
 * any active state (new / in_progress / quoted). The customer-facing reason, if
 * any, is conveyed through the message thread ({@see PostQuoteMessage}); this only
 * moves the status.
 */
class RejectQuoteRequest
{
    public function __construct(private readonly ChangeQuoteRequestStatus $changeStatus) {}

    public function __invoke(QuoteRequest $quoteRequest, ?User $actor = null): QuoteRequest
    {
        return ($this->changeStatus)($quoteRequest, QuoteRequestStatus::Rejected, $actor);
    }
}
