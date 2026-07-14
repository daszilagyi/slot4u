<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Events\BookingStatusChanged;
use App\Notifications\BookingRejectedNotification;
use App\Services\Notification\CustomerNotifier;

/**
 * Emails the customer when their booking request is turned down in the approval
 * flow (docs/04 §5, SLO-26/SLO-109). Only a transition INTO `rejected` triggers it;
 * the `booking:{id}` dedup key (under the booking_rejected type) keeps a repeated
 * event from mailing twice.
 */
class SendBookingRejection
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function handle(BookingStatusChanged $event): void
    {
        if ($event->to !== BookingStatus::Rejected) {
            return;
        }

        $booking = $event->booking;
        $tenant = $this->notifier->operationalTenant($booking);

        if ($tenant === null) {
            return;
        }

        $this->notifier->sendToCustomer(
            tenant: $tenant,
            type: NotificationType::BookingRejected,
            dedupeKey: 'booking:'.$booking->getKey(),
            customer: $booking->customer,
            notification: new BookingRejectedNotification($booking, $tenant),
        );
    }
}
