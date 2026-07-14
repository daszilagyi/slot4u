<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Enums\QuoteRequestStatus;
use App\Events\QuoteRequestStatusChanged;
use App\Notifications\QuoteReadyNotification;

/**
 * Emails the customer when the tenant prices up their quote request, i.e. it
 * reaches `quoted` (docs/04 §6, SLO-27/SLO-109). `quoted` is entered once in the
 * lifecycle (new → in_progress → quoted), so `quote_request:{id}` is a stable dedup
 * key.
 */
class SendQuoteReady extends SendsCustomerNotification
{
    public function handle(QuoteRequestStatusChanged $event): void
    {
        if ($event->to !== QuoteRequestStatus::Quoted) {
            return;
        }

        $quoteRequest = $event->quoteRequest;
        $tenant = $this->operationalTenant($quoteRequest);

        if ($tenant === null) {
            return;
        }

        $this->sendToCustomer(
            tenant: $tenant,
            type: NotificationType::QuoteReady,
            dedupeKey: 'quote_request:'.$quoteRequest->getKey(),
            customer: $quoteRequest->customer,
            notification: new QuoteReadyNotification($quoteRequest, $tenant),
        );
    }
}
