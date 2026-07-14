<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\WaitlistOffered;
use App\Notifications\WaitlistOfferNotification;

/**
 * Emails a waitlisted customer that a seat freed up and is theirs until
 * `offered_until` (docs/04 §3, SLO-25/SLO-109). Without this mail the offer window
 * would elapse unseen, so the seat must never be offered silently.
 *
 * An entry is offered at most once (it goes on to converted/expired and is never
 * re-offered), so `waitlist_entry:{id}` is a stable dedup key — and it keeps the
 * hourly expiry job from re-mailing an entry it chained an offer to.
 */
class SendWaitlistOffer extends SendsCustomerNotification
{
    public function handle(WaitlistOffered $event): void
    {
        $entry = $event->entry;
        $tenant = $this->operationalTenant($entry);

        if ($tenant === null) {
            return;
        }

        $this->sendToCustomer(
            tenant: $tenant,
            type: NotificationType::WaitlistOffer,
            dedupeKey: 'waitlist_entry:'.$entry->getKey(),
            customer: $entry->customer,
            notification: new WaitlistOfferNotification($entry, $tenant),
        );
    }
}
