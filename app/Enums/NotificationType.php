<?php

namespace App\Enums;

/**
 * The kinds of outgoing notification the platform records in `notifications_log`
 * (docs/02). Booking-lifecycle emails land first (SLO-108/SLO-109); the payment
 * types arrive with the M6 payment flow.
 */
enum NotificationType: string
{
    case BookingConfirmed = 'booking_confirmed';
    case BookingModified = 'booking_modified';
    case BookingCanceled = 'booking_canceled';
    case BookingRejected = 'booking_rejected';
    case WaitlistOffer = 'waitlist_offer';
    case QuoteReady = 'quote_ready';
    case Reminder24h = 'reminder_24h';
    case PaymentSuccess = 'payment_success';
    case PaymentFailed = 'payment_failed';
}
