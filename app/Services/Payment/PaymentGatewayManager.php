<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentProvider;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Gateways\SandboxGateway;
use RuntimeException;

/**
 * Resolves the {@see PaymentGateway} implementation behind a provider key
 * (SLO-130). The platform picks the driver (`config/payments.php`); a per-tenant
 * choice would live here too once a second adapter ships (SLO-55 / SLO-39).
 */
final class PaymentGatewayManager
{
    public function __construct(private readonly SandboxGateway $sandbox) {}

    /** The gateway new checkouts are opened with. */
    public function default(): PaymentGateway
    {
        $configured = PaymentProvider::tryFrom((string) config('payments.default'));

        if ($configured === null) {
            throw new RuntimeException('Unknown payment provider configured: '.(string) config('payments.default'));
        }

        return $this->for($configured);
    }

    /**
     * The gateway for an existing payment / incoming webhook. Throws for a provider
     * with no adapter yet — a payment row can outlive the driver it was made with.
     */
    public function for(PaymentProvider $provider): PaymentGateway
    {
        return match ($provider) {
            PaymentProvider::Sandbox => $this->sandbox,
            // The real adapters land with SLO-55 (Barion) / SLO-39 (Stripe).
            PaymentProvider::Barion, PaymentProvider::Stripe => throw new RuntimeException(
                'No payment gateway adapter for provider: '.$provider->value
            ),
        };
    }
}
