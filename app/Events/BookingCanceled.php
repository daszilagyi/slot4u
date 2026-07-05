<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking was canceled (docs/04). A specialisation of the status change that M5
 * listeners can subscribe to directly (cancellation email, refund signal, waitlist
 * offer) without inspecting every status transition.
 */
class BookingCanceled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?User $actor = null,
    ) {}
}
