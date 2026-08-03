<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Services\Notification\CustomerNotifier;

/**
 * Emails the customer their booking confirmation the moment the booking reaches
 * `confirmed` — whether it was created straight into `confirmed` (BookingCreated)
 * or promoted there later from requested/pending_payment (BookingStatusChanged).
 * The Notifier's (booking:{id}) dedup key means both paths together send exactly
 * one confirmation (SLO-108).
 *
 * A booking created by a reschedule gets the "your booking was moved" mail instead
 * (docs/04 §2, SLO-109); with the predecessor's cancellation staying silent, a
 * reschedule is exactly one email to the customer.
 */
class SendBookingConfirmation
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function handle(BookingCreated|BookingStatusChanged $event): void
    {
        $booking = $event->booking;

        // An admin who moved the booking without notifying the customer (SLO-44,
        // docs/04 §2) suppresses exactly this mail — the one the move itself would
        // send. A booking that lands in `requested` and is confirmed by hand later
        // re-enters the normal path through BookingStatusChanged, because by then
        // the confirmation is a separate decision.
        if ($event instanceof BookingCreated && ! $event->notifyCustomer) {
            return;
        }

        // For a status change, only react to transitions INTO confirmed; for a
        // fresh booking, only when it was created already confirmed.
        $reachedConfirmed = $event instanceof BookingStatusChanged
            ? $event->to === BookingStatus::Confirmed
            : $booking->status === BookingStatus::Confirmed;

        if (! $reachedConfirmed) {
            return;
        }

        $tenant = $this->notifier->operationalTenant($booking);

        if ($tenant === null) {
            return;
        }

        // The predecessor is persisted on the booking (not just held in memory
        // through the reschedule call), so an approval-gated reschedule — created
        // `requested`, confirmed only when the admin approves it — still mails the
        // modification, not a confirmation.
        $original = $booking->rescheduled_from_id === null ? null : $booking->rescheduledFrom;

        [$type, $notification] = $original === null
            ? [NotificationType::BookingConfirmed, new BookingConfirmedNotification($booking, $tenant)]
            : [NotificationType::BookingModified, new BookingRescheduledNotification($booking, $original, $tenant)];

        $this->notifier->sendToContact(
            tenant: $tenant,
            type: $type,
            dedupeKey: 'booking:'.$booking->getKey(),
            record: $booking,
            notification: $notification,
        );
    }
}
