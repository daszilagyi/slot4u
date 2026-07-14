<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking was canceled (docs/04). A specialisation of the status change that M5
 * listeners can subscribe to directly (cancellation email, refund signal, waitlist
 * offer) without inspecting every status transition.
 *
 * `$rescheduled` marks the cancellation that is merely the first half of a
 * reschedule (docs/04 §2: cancel the old + create the new). The customer keeps
 * their appointment in that case, so the notification listener stays silent and
 * the new booking's single "your booking was moved" mail speaks for both halves
 * (SLO-109).
 */
class BookingCanceled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?User $actor = null,
        public readonly bool $rescheduled = false,
    ) {}
}
