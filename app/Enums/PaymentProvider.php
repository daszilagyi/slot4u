<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The payment gateway that handled a payment (docs/02 `payments.provider`).
 *
 * `sandbox` is the built-in, self-hosted test provider (SLO-130): it drives the
 * whole pending_payment → confirmed flow without an external account, which is
 * what the demo and the E2E tests run on. The real adapters (Barion, Stripe)
 * land with SLO-55 / SLO-39 and only have to implement the same contract.
 */
enum PaymentProvider: string
{
    case Sandbox = 'sandbox';
    case Barion = 'barion';
    case Stripe = 'stripe';
}
