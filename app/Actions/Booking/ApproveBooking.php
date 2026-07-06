<?php

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\InvalidBookingTransitionException;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approves a requested (approval-pending) booking (docs/04 §5, SLO-26): moves it
 * through `approved` to its post-approval status — `pending_payment` when the
 * service needs online payment, otherwise `confirmed`. Both steps go through the
 * sanctioned {@see ChangeBookingStatus} so history + domain events fire and the
 * soft hold is released. The two transitions run in one transaction.
 */
class ApproveBooking
{
    public function __construct(private readonly ChangeBookingStatus $changeStatus) {}

    /**
     * @throws InvalidBookingTransitionException when the booking is not requested
     */
    public function __invoke(Booking $booking, ?User $actor = null): Booking
    {
        return DB::transaction(function () use ($booking, $actor): Booking {
            ($this->changeStatus)($booking, BookingStatus::Approved, $actor);

            $target = $booking->service?->online_payment_required
                ? BookingStatus::PendingPayment
                : BookingStatus::Confirmed;

            return ($this->changeStatus)($booking, $target, $actor);
        });
    }
}
