<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Settings\TenantSettings;

/**
 * Whether a customer may cancel this booking themselves, and if not, why
 * (SLO-129).
 *
 * One rule, three callers: the guard inside {@see CancelBooking}, the members
 * area's button state, and the public confirmation page's. They used to be two
 * copies of the same condition, which is a shape that drifts — a button offered
 * for something the endpoint refuses is a worse experience than no button, and
 * a button hidden from something the endpoint would allow is a support ticket.
 *
 * Returns a translation key rather than a boolean so the refusal can say which
 * of the two reasons applied. "Cancellation is switched off here" and "you are
 * inside the notice period" are different facts, and telling someone the wrong
 * one sends them to the wrong place.
 */
final class OnlineCancellation
{
    public const REFUSED_DISABLED = 'app.booking.error.cancel_online_disabled';

    public const REFUSED_DEADLINE = 'app.booking.error.cancel_deadline_passed';

    public const REFUSED_STATUS = 'app.booking.error.cancel_not_allowed';

    /** Null when the customer may cancel; otherwise the reason's translation key. */
    public static function refusal(Booking $booking, TenantSettings $settings): ?string
    {
        if (! $booking->status->canTransitionTo(BookingStatus::Canceled)) {
            return self::REFUSED_STATUS;
        }

        if (! $settings->onlineCancellationEnabled) {
            return self::REFUSED_DISABLED;
        }

        if ($booking->isWithinCancellationDeadline($settings->cancellationDeadlineHours)) {
            return self::REFUSED_DEADLINE;
        }

        return null;
    }

    public static function allowed(Booking $booking, TenantSettings $settings): bool
    {
        return self::refusal($booking, $settings) === null;
    }
}
