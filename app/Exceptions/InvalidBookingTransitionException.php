<?php

namespace App\Exceptions;

use App\Actions\Booking\ChangeBookingStatus;
use App\Enums\BookingStatus;
use RuntimeException;

/**
 * Thrown when a booking status transition is not allowed by the state machine
 * (docs/04 §Közös állapotgép). Guards every {@see ChangeBookingStatus}.
 */
class InvalidBookingTransitionException extends RuntimeException
{
    public function __construct(
        public readonly BookingStatus $from,
        public readonly BookingStatus $to,
    ) {
        parent::__construct(sprintf('Cannot transition a booking from %s to %s.', $from->value, $to->value));
    }
}
