<?php

namespace App\Events;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking moved from one status to another (docs/04). Carries the old/new status
 * and the actor so M5 listeners can notify the right people.
 */
class BookingStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingStatus $from,
        public readonly BookingStatus $to,
        public readonly ?User $actor = null,
    ) {}
}
