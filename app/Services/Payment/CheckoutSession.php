<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * What a gateway hands back when a checkout is opened (SLO-130): the URL the
 * customer must be sent to, and the gateway's own transaction reference, which we
 * store on the payment row and later match the webhook against.
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $redirectUrl,
        public readonly string $providerRef,
    ) {}
}
