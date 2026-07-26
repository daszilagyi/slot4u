<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands a recorded refund to the payment gateway (SLO-131).
 *
 * Queued and `afterCommit`, so the external call happens only once the cancelling
 * transaction is durable — a refund must never leave the account for a booking
 * whose cancellation rolled back. A gateway refusal marks the refund `failed` and
 * leaves it visible on the booking page for a manual retry rather than throwing
 * the obligation away.
 *
 * Takes the refund id (not the model) so a retry always reads the current row.
 */
class ProcessRefund implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $refundId)
    {
        // Only run once the transaction that recorded the refund has committed: a
        // refund must never leave the account for a cancellation that rolled back.
        // (Set here rather than as a property — the Queueable trait declares it.)
        $this->afterCommit();
    }

    public function handle(PaymentGatewayManager $gateways): void
    {
        $refund = Refund::withoutGlobalScopes()->find($this->refundId);

        // Idempotent: a retry of an already settled refund is a no-op.
        if (! $refund instanceof Refund || ! $refund->status->isOpen()) {
            return;
        }

        $payment = Payment::withoutGlobalScopes()->find($refund->payment_id);

        if (! $payment instanceof Payment) {
            return;
        }

        try {
            $reference = $gateways->for($payment->provider)->refund($payment, $refund->amount_minor);
        } catch (Throwable $e) {
            $refund->status = RefundStatus::Failed;
            $refund->saveQuietly();

            Log::warning('Refund refused by the payment gateway', [
                'refund_id' => $refund->getKey(),
                'payment_id' => $payment->getKey(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        DB::transaction(function () use ($refund, $payment, $reference): void {
            $refund->status = RefundStatus::Completed;
            $refund->provider_ref = $reference;
            $refund->refunded_at = Carbon::now();
            $refund->saveQuietly();

            // The payment reads `refunded` only once the whole settled amount is
            // back with the customer; a partial refund leaves it `paid` and the
            // refunds rows carry the detail (docs/02).
            $returned = (int) Refund::withoutGlobalScopes()
                ->where('payment_id', $payment->getKey())
                ->where('status', RefundStatus::Completed->value)
                ->sum('amount_minor');

            if ($returned >= $payment->amount_minor && $payment->status === PaymentStatus::Paid) {
                $payment->status = PaymentStatus::Refunded;
                $payment->saveQuietly();
            }
        });
    }
}
