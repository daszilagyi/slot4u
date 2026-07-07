<?php

namespace App\Actions\Quote;

use App\Enums\BookingMode;
use App\Events\QuoteRequestCreated;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Models\User;
use RuntimeException;

/**
 * Creates a quote request against a quote_request-mode service (docs/04 §6,
 * SLO-27). The customer's filled-in form lands in `parameters`; the request
 * starts in `new` and fires {@see QuoteRequestCreated} so M5 can notify the
 * tenant. Feature gating (feature_quote_request) is enforced at the request/route
 * layer, mirroring the rest of the engine.
 */
class CreateQuoteRequest
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Service $service, array $data, ?User $actor = null): QuoteRequest
    {
        if ($service->booking_mode !== BookingMode::QuoteRequest) {
            throw new RuntimeException('CreateQuoteRequest requires a quote_request-mode service.');
        }

        $quoteRequest = new QuoteRequest;
        $quoteRequest->fill([
            'service_id' => $service->id,
            'customer_id' => $data['customer_id'] ?? null,
            'parameters' => $data['parameters'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
        ]);
        // tenant_id is stamped by BelongsToTenant; status defaults to `new`.
        $quoteRequest->save();

        QuoteRequestCreated::dispatch($quoteRequest);

        return $quoteRequest;
    }
}
