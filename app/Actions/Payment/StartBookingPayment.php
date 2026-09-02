<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Payment\CheckoutSession;
use App\Services\Payment\Contracts\PaymentGateway;
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

        $gateway = $this->gatewayFor($booking);
        $amount = self::chargeableMinor($booking);

        return DB::transaction(function () use ($booking, $gateway, $returnUrl, $amount): CheckoutSession {
            $payment = new Payment([
                'booking_id' => $booking->getKey(),
                'provider' => $gateway->provider(),
                'amount_minor' => $amount,
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

    /**
     * The gateway this booking's tenant pays through — the sandbox for a demo
     * tenant, the configured default for everyone else (SLO-182).
     *
     * The tenant is looked up by key rather than through `$booking->tenant`, and
     * `withTrashed()` is the reason: archiving soft-deletes the tenant, so the
     * relation would resolve to null exactly then and the null branch would fall
     * back to the real gateway. A demo tenant does not stop being one when it is
     * archived. The lookup is a primary-key read on the way into a gateway HTTP
     * round-trip, so its cost is not measurable here.
     */
    private function gatewayFor(Booking $booking): PaymentGateway
    {
        $tenant = Tenant::withTrashed()->find($booking->tenant_id);

        return $tenant === null
            ? $this->gateways->default()
            : $this->gateways->forTenant($tenant);
    }

    /**
     * What the customer is charged online now: a resource_rental's deposit when the
     * service asks for one, otherwise the whole booking price (docs/04 §4,
     * SLO-131). The rest of a deposit booking is settled on site — the booking's
     * own `price_minor` (and with it the commission base, docs/10 §3) is unchanged.
     */
    public static function chargeableMinor(Booking $booking): int
    {
        return $booking->service?->depositMinor() ?? $booking->price_minor;
    }
}
