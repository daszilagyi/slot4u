<?php

namespace App\Exceptions;

use App\Actions\Quote\ChangeQuoteRequestStatus;
use App\Enums\QuoteRequestStatus;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a quote request status transition is not allowed by the state
 * machine (docs/04 §6, SLO-27). Guards every {@see ChangeQuoteRequestStatus}.
 * render() turns it into a 422 (not a 500) for both web (Inertia) and JSON
 * callers — e.g. accepting a request that was never quoted.
 */
class InvalidQuoteTransitionException extends RuntimeException
{
    public function __construct(
        public readonly QuoteRequestStatus $from,
        public readonly QuoteRequestStatus $to,
    ) {
        parent::__construct(sprintf('Cannot transition a quote request from %s to %s.', $from->value, $to->value));
    }

    public function render(Request $request): mixed
    {
        $message = __('app.quote.error.invalid_transition');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withErrors(['quote_request' => $message]);
    }
}
