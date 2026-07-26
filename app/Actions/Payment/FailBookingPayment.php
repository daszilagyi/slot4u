<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Console\Commands\ExpirePendingPayments;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Closes a checkout that did not result in money: refused by the gateway, or
 * abandoned until the booking's payment hold expired (SLO-130).
 *
 * The booking is deliberately left in `pending_payment` — a refused card is not a
 * cancelled booking, and the customer can retry from the confirmation page until
 * the hold runs out ({@see ExpirePendingPayments}).
 * Idempotent: only an open attempt is moved.
 */
final class FailBookingPayment
{
    /**
     * @param  array<string, mixed>  $payload  the verified gateway callback, stored for audit
     */
    public function __invoke(Payment $payment, array $payload = []): Payment
    {
        return DB::transaction(function () use ($payment, $payload): Payment {
            $locked = Payment::withoutGlobalScopes()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Payment || ! $locked->status->isOpen()) {
                return $locked ?? $payment;
            }

            $locked->status = PaymentStatus::Failed;
            if ($payload !== []) {
                $locked->payload = $payload;
            }
            $locked->saveQuietly();

            return $locked;
        });
    }
}
