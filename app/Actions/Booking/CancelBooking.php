<?php

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Settings\TenantSettings;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a booking (docs/04 §5, SLO-23). An online (customer) cancellation is
 * refused once inside the tenant's cancellation deadline; an admin cancellation
 * is always allowed. Delegates the actual transition + history + events to
 * {@see ChangeBookingStatus}.
 */
class CancelBooking
{
    public function __construct(private readonly ChangeBookingStatus $changeStatus) {}

    /**
     * @param  bool  $rescheduled  this cancellation is the first half of a reschedule
     *                             (docs/04 §2) — passed on so the notification
     *                             listeners stay silent for it (SLO-109)
     *
     * @throws ValidationException when an online cancellation is past the deadline
     */
    public function __invoke(Booking $booking, ?User $actor = null, ?string $reason = null, bool $online = false, bool $rescheduled = false): Booking
    {
        if ($online) {
            // withTrashed: an archived tenant's settings must still resolve for a
            // late online-cancel attempt (fromArray(null) falls back to defaults).
            $tenant = $booking->tenant()->withTrashed()->first();
            $deadlineHours = TenantSettings::fromArray($tenant?->settings)->cancellationDeadlineHours;

            if ($booking->isWithinCancellationDeadline($deadlineHours)) {
                throw ValidationException::withMessages([
                    'cancel' => __('app.booking.error.cancel_deadline_passed', ['hours' => $deadlineHours]),
                ]);
            }
        }

        return ($this->changeStatus)($booking, BookingStatus::Canceled, $actor, $reason, $rescheduled);
    }
}
