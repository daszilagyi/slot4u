<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a booking can't be created because the slot was taken or the event
 * is full (docs/04 race protection, SLO-24). The message is already localised;
 * render() turns it into a 422 validation-style response for both web (Inertia)
 * and JSON API callers.
 */
class SlotUnavailableException extends RuntimeException
{
    public static function slotTaken(): self
    {
        return new self(__('app.booking.error.slot_unavailable'));
    }

    public static function eventFull(): self
    {
        return new self(__('app.booking.error.event_full'));
    }

    public function render(Request $request): mixed
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withErrors(['booking' => $this->getMessage()]);
    }
}
