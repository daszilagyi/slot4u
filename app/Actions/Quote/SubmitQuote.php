<?php

namespace App\Actions\Quote;

use App\Enums\QuoteRequestStatus;
use App\Events\QuoteRequestStatusChanged;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Works a quote request up into a price and moves it to `quoted` (docs/04 §6,
 * SLO-27). A fresh `new` request is walked through `in_progress` first so an admin
 * can quote in one step; the price + validity are the customer-facing offer. The
 * `quoted` transition fires {@see QuoteRequestStatusChanged} for the
 * M5 "your quote is ready" notification.
 */
class SubmitQuote
{
    public function __construct(private readonly ChangeQuoteRequestStatus $changeStatus) {}

    public function __invoke(
        QuoteRequest $quoteRequest,
        int $priceMinor,
        string $currency,
        ?Carbon $validUntil = null,
        ?string $internalNotes = null,
        ?User $actor = null,
    ): QuoteRequest {
        return DB::transaction(function () use ($quoteRequest, $priceMinor, $currency, $validUntil, $internalNotes, $actor): QuoteRequest {
            // Let an admin quote straight from `new` (docs/04 §6 flow allows the
            // in_progress step to be implicit).
            if ($quoteRequest->status === QuoteRequestStatus::New) {
                ($this->changeStatus)($quoteRequest, QuoteRequestStatus::InProgress, $actor);
            }

            // price_minor / currency / valid_until are guarded — set from the action.
            $quoteRequest->price_minor = $priceMinor;
            $quoteRequest->currency = $currency;
            $quoteRequest->valid_until = $validUntil;
            if ($internalNotes !== null) {
                $quoteRequest->internal_notes = $internalNotes;
            }

            // ChangeQuoteRequestStatus saves the model, persisting the pricing above.
            return ($this->changeStatus)($quoteRequest, QuoteRequestStatus::Quoted, $actor);
        });
    }
}
