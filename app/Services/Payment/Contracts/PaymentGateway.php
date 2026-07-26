<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Services\Payment\CheckoutSession;
use App\Services\Payment\WebhookResult;
use Illuminate\Http\Request;

/**
 * The provider abstraction the customer payment flow talks to (SLO-40 / SLO-130).
 * Everything above this line (actions, controller, expiry sweep) is provider
 * agnostic, so adding Barion or Stripe (SLO-55 / SLO-39) is a new implementation,
 * not a rewrite — the same rule docs/01 §4 applies to the Phase-2 API.
 */
interface PaymentGateway
{
    public function provider(): PaymentProvider;

    /**
     * Open a checkout for an already-persisted, pending payment and return where
     * to send the customer plus the gateway's transaction reference.
     *
     * @param  string  $returnUrl  where the gateway sends the customer back to
     */
    public function startCheckout(Payment $payment, string $returnUrl): CheckoutSession;

    /**
     * Verify and interpret a gateway callback. MUST return null for anything that
     * is not provably authentic (bad/missing signature, unparseable body) — the
     * caller treats a null as "reject the request", never as "payment failed".
     */
    public function parseWebhook(Request $request): ?WebhookResult;
}
