<?php

namespace App\Actions\Quote;

use App\Actions\Booking\CreateBooking;
use App\Enums\BookingSource;
use App\Enums\QuoteRequestStatus;
use App\Exceptions\InvalidQuoteTransitionException;
use App\Models\Booking;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Accepts a quoted request and — optionally — generates the booking it stands for
 * (docs/04 §6, SLO-27). The booking is created through {@see CreateBooking} (the
 * single sanctioned creator), carrying the accepted quote price rather than the
 * service list price, and linked back via `booking_id`. Runs in one transaction so
 * a booking-creation failure rolls the acceptance back.
 */
class AcceptQuoteRequest
{
    public function __construct(
        private readonly ChangeQuoteRequestStatus $changeStatus,
        private readonly CreateBooking $createBooking,
    ) {}

    public function __invoke(QuoteRequest $quoteRequest, ?User $actor = null, bool $generateBooking = true): QuoteRequest
    {
        // Guard the transition before doing any work (booking generation) so an
        // illegal accept (e.g. a never-quoted request) fails cleanly with a 422
        // and nothing is created. ChangeQuoteRequestStatus re-checks below.
        if (! $quoteRequest->status->canTransitionTo(QuoteRequestStatus::Accepted)) {
            throw new InvalidQuoteTransitionException($quoteRequest->status, QuoteRequestStatus::Accepted);
        }

        return DB::transaction(function () use ($quoteRequest, $actor, $generateBooking): QuoteRequest {
            if ($generateBooking) {
                $quoteRequest->booking_id = $this->generateBooking($quoteRequest, $actor)->id;
            }

            // ChangeQuoteRequestStatus saves the model, persisting booking_id above.
            return ($this->changeStatus)($quoteRequest, QuoteRequestStatus::Accepted, $actor);
        });
    }

    private function generateBooking(QuoteRequest $quoteRequest, ?User $actor): Booking
    {
        if ($quoteRequest->price_minor === null || $quoteRequest->currency === null) {
            throw new RuntimeException('Cannot generate a booking for a quote request without a price.');
        }

        // Tenant-anchored load (defense-in-depth: the action may run outside a
        // request scope where the ambient TenantScope is a no-op).
        $service = Service::query()
            ->where('tenant_id', $quoteRequest->tenant_id)
            ->findOrFail($quoteRequest->service_id);

        return ($this->createBooking)($service, [
            'customer_id' => $quoteRequest->customer_id,
            // The accepted quote is authoritative (docs/04 §6) — override the list price.
            'price_minor' => $quoteRequest->price_minor,
            'currency' => $quoteRequest->currency,
            // Admin-accepted: skips the approval/payment gates → confirmed.
            'source' => BookingSource::Admin->value,
            // Authorises the quote_request-mode create path (CreateBooking refuses
            // it otherwise, so it can't be reached via the generic /bookings endpoint).
            'from_quote_acceptance' => true,
        ], $actor);
    }
}
