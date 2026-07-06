<?php

namespace App\Exceptions;

use App\Actions\Booking\ChangeBookingStatus;
use App\Enums\BookingStatus;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a booking status transition is not allowed by the state machine
 * (docs/04 §Közös állapotgép). Guards every {@see ChangeBookingStatus}. render()
 * turns it into a 422 (not a 500) for both web (Inertia) and JSON callers — e.g. an
 * admin approving a booking that is no longer requested (SLO-26).
 */
class InvalidBookingTransitionException extends RuntimeException
{
    public function __construct(
        public readonly BookingStatus $from,
        public readonly BookingStatus $to,
    ) {
        parent::__construct(sprintf('Cannot transition a booking from %s to %s.', $from->value, $to->value));
    }

    public function render(Request $request): mixed
    {
        $message = __('app.booking.error.invalid_transition');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withErrors(['booking' => $message]);
    }
}
