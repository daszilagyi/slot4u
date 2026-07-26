<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentStatus;

/**
 * A verified gateway callback (SLO-130), reduced to what the domain needs: which
 * payment it is about and whether the money arrived. The gateway implementation
 * is responsible for rejecting an unsigned/forged callback BEFORE producing one
 * of these — a WebhookResult means "authentic".
 */
final class WebhookResult
{
    /**
     * @param  PaymentStatus  $status  Paid or Failed — a gateway never reports back "pending"
     * @param  array<string, mixed>  $payload  masked callback body, stored for audit (docs/06)
     */
    public function __construct(
        public readonly string $providerRef,
        public readonly PaymentStatus $status,
        public readonly array $payload = [],
    ) {}
}
