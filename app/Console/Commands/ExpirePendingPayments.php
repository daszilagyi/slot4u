<?php

namespace App\Console\Commands;

use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Payment\FailBookingPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Console\Command;

/**
 * Releases bookings whose payment window has lapsed (docs/04, SLO-130): a
 * `pending_payment` booking holds its slot only until `hold_expires_at`, then it is
 * cancelled through {@see ChangeBookingStatus} so the slot frees, history + events
 * fire and an event seat is returned (SLO-25). Its open checkout attempts are
 * closed as failed.
 *
 * Before this existed a never-paid booking held its slot forever (the soft-hold
 * sweep only covers approval-pending bookings). Scheduled every ten minutes:
 * the hold is minute-grained, unlike the hour-grained approval hold.
 * Idempotent — a paid or cancelled booking no longer matches.
 */
class ExpirePendingPayments extends Command
{
    protected $signature = 'bookings:expire-pending-payments';

    protected $description = 'Cancel bookings whose online payment window has expired and free their slots.';

    public function handle(ChangeBookingStatus $changeStatus, FailBookingPayment $failPayment): int
    {
        // Tenant-less: scans all tenants. hold_expires_at was stamped per tenant at
        // creation, so a single deadline comparison is correct everywhere.
        $expired = Booking::query()
            ->where('status', BookingStatus::PendingPayment->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->get();

        foreach ($expired as $booking) {
            $changeStatus($booking, BookingStatus::Canceled, null, __('app.booking.reason.payment_expired'));

            $openAttempts = Payment::withoutGlobalScopes()
                ->where('booking_id', $booking->getKey())
                ->where('status', PaymentStatus::Pending->value)
                ->get();

            foreach ($openAttempts as $payment) {
                $failPayment($payment);
            }
        }

        $this->info("Released {$expired->count()} expired payment hold(s).");

        return self::SUCCESS;
    }
}
