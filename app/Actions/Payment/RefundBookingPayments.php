<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Jobs\ProcessRefund;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Settings\TenantSettings;

/**
 * Records what a cancelled booking owes its customer back (docs/04 §5, SLO-131)
 * and hands the actual gateway call to {@see ProcessRefund}.
 *
 * The obligation is written first, in the cancelling transaction, and the money
 * moves after: a gateway outage then leaves an auditable pending refund the tenant
 * can retry, instead of a silently swallowed promise. The split also keeps an
 * external HTTP call out of the booking transaction.
 *
 * The amount defaults to the tenant's refund policy (`settings.refund_policy`) and
 * can be overridden by an admin, which is how a one-off goodwill (or reduced)
 * refund is issued. Whatever the amount, the commission ledger is untouched:
 * slot4u bills on the booking list price, not on what the customer kept (docs/10 §3).
 */
final class RefundBookingPayments
{
    /**
     * Override meaning "give back everything still refundable", whatever was
     * actually charged (a deposit may be less than the booking price). The per
     * payment cap turns it into the real amount.
     */
    public const FULL_REFUND = PHP_INT_MAX;

    /**
     * @param  int|null  $overrideMinor  refund exactly this much per settled payment
     *                                   instead of the policy amount (admin override
     *                                   / a full refund for a cancelled event)
     * @return list<Refund> the refunds recorded (empty when nothing is owed)
     */
    public function __invoke(Booking $booking, ?int $overrideMinor = null, ?string $reason = null): array
    {
        $tenant = Tenant::withoutGlobalScopes()->withTrashed()->find($booking->tenant_id);
        $settings = TenantSettings::fromArray($tenant?->settings);

        $payments = Payment::withoutGlobalScopes()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Paid->value)
            ->get();

        $refunds = [];

        foreach ($payments as $payment) {
            // Never give back more than is still refundable on this payment: earlier
            // partial refunds (and a pending one already queued) count against it.
            $refundable = $payment->amount_minor - $this->alreadyRefunded($payment);
            $amount = min(
                $overrideMinor ?? $settings->automaticRefundMinor($payment->amount_minor),
                $refundable
            );

            if ($amount <= 0) {
                continue;
            }

            $refund = new Refund([
                'payment_id' => $payment->getKey(),
                'amount_minor' => $amount,
                'currency' => $payment->currency,
                'reason' => $reason,
            ]);
            // Explicit tenant stamp + quiet save: this runs from tenant-less callers
            // too (the expiry sweep, a queue job), where the creating hook is a no-op.
            $refund->tenant_id = (int) $payment->tenant_id;
            $refund->saveQuietly();

            // afterCommit on the job: the gateway must not be called for a refund
            // whose enclosing cancellation later rolls back.
            ProcessRefund::dispatch($refund->getKey());

            $refunds[] = $refund;
        }

        return $refunds;
    }

    /**
     * What is already promised or paid back on this payment — everything except the
     * refunds the gateway refused ({@see RefundStatus::countsAgainstPayment()}).
     */
    private function alreadyRefunded(Payment $payment): int
    {
        return (int) Refund::withoutGlobalScopes()
            ->where('payment_id', $payment->getKey())
            ->where('status', '!=', RefundStatus::Failed->value)
            ->sum('amount_minor');
    }

    /**
     * The refundable balance of a booking's settled payments — what an admin may
     * still hand back today (SLO-131). Used by the booking page and its form rules.
     */
    public function refundableMinor(Booking $booking): int
    {
        $payments = Payment::withoutGlobalScopes()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Paid->value)
            ->get();

        return (int) $payments->sum(fn (Payment $payment): int => max(
            0,
            $payment->amount_minor - $this->alreadyRefunded($payment)
        ));
    }
}
