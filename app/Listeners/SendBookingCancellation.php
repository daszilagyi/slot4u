<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\BookingCanceled;
use App\Notifications\BookingCanceledNotification;

/**
 * Emails the customer that their booking was canceled (docs/04 §5, SLO-109) —
 * whichever side canceled it.
 *
 * A cancellation that is only the first half of a reschedule is silent: the
 * customer still has an appointment, and the replacement booking sends the single
 * "your booking was moved" mail (docs/04 §2).
 */
class SendBookingCancellation extends SendsCustomerNotification
{
    public function handle(BookingCanceled $event): void
    {
        if ($event->rescheduled) {
            return;
        }

        $booking = $event->booking;
        $tenant = $this->operationalTenant($booking);

        if ($tenant === null) {
            return;
        }

        $this->sendToCustomer(
            tenant: $tenant,
            type: NotificationType::BookingCanceled,
            dedupeKey: 'booking:'.$booking->getKey(),
            customer: $booking->customer,
            notification: new BookingCanceledNotification($booking, $tenant),
        );
    }
}
