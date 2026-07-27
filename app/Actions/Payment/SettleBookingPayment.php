<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Invoice\RecordInvoiceForPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Settles a paid checkout and confirms the booking behind it (docs/04 állapotgép:
 * `pending_payment --paid--> confirmed`, SLO-130).
 *
 * Idempotent on the payment row: a gateway that retries its callback (they all do)
 * finds the payment already settled and changes nothing — the booking is confirmed
 * exactly once, so the confirmation mail and the commission ledger entry are too.
 * The transition goes through {@see ChangeBookingStatus}, never a direct write, so
 * history + domain events + the commission ledger stay in step.
 */
final class SettleBookingPayment
{
    public function __construct(
        private readonly ChangeBookingStatus $changeStatus,
        private readonly RefundBookingPayments $refundPayments,
        private readonly RecordInvoiceForPayment $recordInvoice,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the verified gateway callback, stored for audit
     */
    public function __invoke(Payment $payment, array $payload = []): Payment
    {
        return DB::transaction(function () use ($payment, $payload): Payment {
            // Serialise concurrent callbacks for the same payment (a gateway retry
            // racing the customer's browser return).
            $locked = Payment::withoutGlobalScopes()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Payment || ! $locked->status->isOpen()) {
                return $locked ?? $payment;
            }

            $locked->status = PaymentStatus::Paid;
            $locked->paid_at = Carbon::now();
            $locked->payload = $payload;
            $locked->saveQuietly();

            $booking = Booking::withoutGlobalScopes()->find($locked->booking_id);

            if ($booking instanceof Booking && $booking->status === BookingStatus::PendingPayment) {
                ($this->changeStatus)($booking, BookingStatus::Confirmed);

                // Any other checkout the customer left open on this booking can no
                // longer be paid — close it so the booking has a single live attempt.
                Payment::withoutGlobalScopes()
                    ->where('booking_id', $locked->booking_id)
                    ->whereKeyNot($locked->getKey())
                    ->where('status', PaymentStatus::Pending->value)
                    ->update(['status' => PaymentStatus::Failed->value]);

                // Money in, invoice out (SLO-133) — only for a booking that stands;
                // the refunded-away case below has nothing to invoice. No-op unless
                // the tenant has the invoicing integration on.
                ($this->recordInvoice)($locked);
            } elseif ($booking instanceof Booking && $booking->status === BookingStatus::Canceled) {
                // Money for a booking that no longer exists: the hold expired and the
                // slot was released just before the callback landed. The customer gets
                // it all back automatically — they have nothing to show for it
                // (SLO-131). The payment stays recorded, with the refund against it.
                Log::warning('Payment settled after its booking was released — refunding in full', [
                    'payment_id' => $locked->getKey(),
                    'booking_id' => $locked->booking_id,
                ]);

                ($this->refundPayments)(
                    $booking,
                    RefundBookingPayments::FULL_REFUND,
                    __('app.booking.reason.payment_expired'),
                );
            } else {
                Log::warning('Payment settled for a booking that no longer awaits payment', [
                    'payment_id' => $locked->getKey(),
                    'booking_id' => $locked->booking_id,
                    'booking_status' => $booking?->status->value,
                ]);
            }

            return $locked;
        });
    }
}
