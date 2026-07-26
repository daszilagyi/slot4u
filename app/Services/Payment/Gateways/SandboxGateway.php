<?php

declare(strict_types=1);

namespace App\Services\Payment\Gateways;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Payment\CheckoutSession;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The built-in test gateway (SLO-130): it hosts its own checkout page inside the
 * app, so the full pending_payment → confirmed path (and the unpaid → slot freed
 * path) is demoable and E2E-testable without an external merchant account.
 *
 * It is deliberately NOT a stub: it mints an unguessable transaction reference and
 * signs its callbacks with HMAC-SHA256 exactly like a real gateway, so the webhook
 * endpoint is exercised by the same verification code a Barion/Stripe adapter will
 * use. The checkout page itself is disabled outside local/staging
 * (`payments.sandbox.enabled`).
 */
final class SandboxGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Sandbox-Signature';

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Sandbox;
    }

    public function startCheckout(Payment $payment, string $returnUrl): CheckoutSession
    {
        $reference = 'sbx_'.Str::lower(Str::random(32));

        // Relative URL: it keeps the customer on the current tenant subdomain,
        // which is also where the payment (and its tenant scope) lives.
        return new CheckoutSession(
            redirectUrl: '/payments/sandbox/'.$reference,
            providerRef: $reference,
        );
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        $reference = $request->input('reference');
        $status = $request->input('status');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if (! is_string($reference) || $reference === '' || ! is_string($status)) {
            return null;
        }

        $paymentStatus = match ($status) {
            PaymentStatus::Paid->value => PaymentStatus::Paid,
            PaymentStatus::Failed->value => PaymentStatus::Failed,
            default => null,
        };

        if ($paymentStatus === null || ! hash_equals(self::sign($reference, $status), $signature)) {
            return null;
        }

        return new WebhookResult(
            providerRef: $reference,
            status: $paymentStatus,
            payload: ['reference' => $reference, 'status' => $status],
        );
    }

    /**
     * The sandbox always accepts a refund — the failure path is exercised in tests
     * by swapping in a gateway double, not by making this one flaky.
     */
    public function refund(Payment $payment, int $amountMinor): string
    {
        return 'sbxr_'.Str::lower(Str::random(32));
    }

    /**
     * The signature a caller must present for a callback to be accepted. Public so
     * the test suite (and a manual demo) can produce an authentic callback without
     * duplicating the scheme.
     */
    public static function sign(string $reference, string $status): string
    {
        return hash_hmac('sha256', $reference.'|'.$status, (string) config('payments.sandbox.secret'));
    }
}
