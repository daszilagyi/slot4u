<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Notifications\BookingConfirmedNotification;
use App\Services\Notification\Notifier;

/**
 * Emails the customer their booking confirmation the moment the booking reaches
 * `confirmed` — whether it was created straight into `confirmed` (BookingCreated)
 * or promoted there later from requested/pending_payment (BookingStatusChanged).
 * The Notifier's (booking:{id}) dedup key means both paths together send exactly
 * one confirmation (SLO-108).
 */
class SendBookingConfirmation
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(BookingCreated|BookingStatusChanged $event): void
    {
        $booking = $event->booking;

        // For a status change, only react to transitions INTO confirmed; for a
        // fresh booking, only when it was created already confirmed.
        $reachedConfirmed = $event instanceof BookingStatusChanged
            ? $event->to === BookingStatus::Confirmed
            : $booking->status === BookingStatus::Confirmed;

        if (! $reachedConfirmed) {
            return;
        }

        $customer = $booking->customer;

        // A booking always has a customer, but never notify a missing/emailless one.
        if ($customer === null || blank($customer->email)) {
            return;
        }

        $this->notifier->sendOnce(
            tenant: $booking->tenant,
            type: NotificationType::BookingConfirmed,
            dedupeKey: $this->dedupeKey($booking),
            recipient: $customer,
            recipientEmail: $customer->email,
            notification: new BookingConfirmedNotification($booking),
        );
    }

    private function dedupeKey(Booking $booking): string
    {
        return 'booking:'.$booking->getKey();
    }
}
