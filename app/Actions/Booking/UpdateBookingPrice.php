<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\AuditAction;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Events\BookingPriceChanged;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a booking's list price after the fact (docs/10 §3.3, SLO-126).
 *
 * The list price is the commission base, so this is not an ordinary field edit:
 * the ledger entry's `amount_minor` has to follow, and if the booking's period
 * was already invoiced the difference becomes a credit on the current open one
 * (§8.2). Both of those already work — they hang off the commission listener —
 * so all this action has to do is dispatch the event inside the transaction.
 *
 * Two edits are refused outright, because the price would then disagree with a
 * record that has already left the building:
 *
 *  - a payment is in flight — the customer is looking at a checkout page for
 *    the old amount;
 *  - an invoice has been issued — a document stating the old amount is in the
 *    customer's hands, and correcting that needs a corrective invoice, which
 *    the invoicing backbone deliberately does not do yet (SLO-133).
 */
class UpdateBookingPrice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Booking $booking, int $priceMinor, ?User $actor = null): Booking
    {
        $from = (int) $booking->price_minor;

        if ($from === $priceMinor) {
            return $booking;
        }

        $this->guardAgainstMoneyAlreadyMoved($booking);

        DB::transaction(function () use ($booking, $from, $priceMinor, $actor): void {
            $booking->price_minor = $priceMinor;
            $booking->save();

            $this->audit->record(
                AuditAction::BookingPriceChanged,
                $booking,
                ['price_minor' => $from],
                ['price_minor' => $priceMinor],
            );

            // Synchronous inside the transaction: the ledger (and, for a closed
            // period, the credit) is written atomically with the new price.
            BookingPriceChanged::dispatch($booking, $from, $priceMinor, $actor);
        });

        return $booking;
    }

    private function guardAgainstMoneyAlreadyMoved(Booking $booking): void
    {
        $hasPendingPayment = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->exists();

        if ($hasPendingPayment) {
            throw ValidationException::withMessages([
                'price_minor' => __('app.admin.bookings.price.blocked_by_payment'),
            ]);
        }

        $hasIssuedInvoice = Invoice::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', InvoiceStatus::Issued->value)
            ->exists();

        if ($hasIssuedInvoice) {
            throw ValidationException::withMessages([
                'price_minor' => __('app.admin.bookings.price.blocked_by_invoice'),
            ]);
        }
    }
}
