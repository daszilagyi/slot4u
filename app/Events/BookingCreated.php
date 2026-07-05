<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking was created (docs/04). Listeners (confirmation email, Reverb
 * broadcast, statistics) are wired in M5; the event is dispatched now so the
 * booking engine stays event-driven from the start.
 */
class BookingCreated
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
