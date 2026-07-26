<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payment\CheckoutSession;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opens a checkout for a booking waiting on payment (docs/04 állapotgép, SLO-130).
 *
 * Each call records its own `payments` row, so an abandoned or refused attempt
 * keeps its audit trail and the customer can retry from the confirmation page. The
 * row is written first and the gateway reference stamped on it after, so a gateway
 * that reports back before we finish never finds a payment it cannot match.
 */
final class StartBookingPayment
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @param  string  $returnUrl  where the gateway sends the customer back to
     *
     * @throws RuntimeException when the booking is not awaiting payment
     */
    public function __invoke(Booking $booking, string $returnUrl): CheckoutSession
    {
        // Only a booking actually waiting on money may open a checkout — this is
        // the Action-layer guard behind the controller's redirect (docs/01 §4: the
        // rule must hold for every entry point, not just the public page).
        if ($booking->status !== BookingStatus::PendingPayment) {
            throw new RuntimeException('Only a pending_payment booking can start a payment (SLO-130).');
        }

        $gateway = $this->gateways->default();

        return DB::transaction(function () use ($booking, $gateway, $returnUrl): CheckoutSession {
            $payment = new Payment([
                'booking_id' => $booking->getKey(),
                'provider' => $gateway->provider(),
                'amount_minor' => $booking->price_minor,
                'currency' => $booking->currency,
            ]);
            // Explicit tenant stamp + quiet save: the action runs off the request
            // cycle too (the Phase-2 API, a retry job), where the ambient tenant
            // scope is a no-op and the creating hook would leave tenant_id unset.
            $payment->tenant_id = (int) $booking->tenant_id;
            $payment->saveQuietly();

            $session = $gateway->startCheckout($payment, $returnUrl);

            $payment->provider_ref = $session->providerRef;
            $payment->saveQuietly();

            return $session;
        });
    }
}
