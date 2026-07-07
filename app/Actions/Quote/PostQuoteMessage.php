<?php

namespace App\Actions\Quote;

use App\Events\QuoteMessagePosted;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\User;

/**
 * Appends a message to a quote request's conversation thread (docs/04 §6,
 * SLO-27) and fires {@see QuoteMessagePosted} so M5 can notify the other party.
 * The author is nullable (a system message); tenant_id is stamped by
 * BelongsToTenant from the ambient tenant.
 */
class PostQuoteMessage
{
    public function __invoke(QuoteRequest $quoteRequest, string $body, ?User $author = null): QuoteRequestMessage
    {
        $message = new QuoteRequestMessage;
        $message->fill([
            'quote_request_id' => $quoteRequest->id,
            'user_id' => $author?->getKey(),
            'body' => $body,
        ]);
        $message->save();

        QuoteMessagePosted::dispatch($message);

        return $message;
    }
}
