<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Payment\FailBookingPayment;
use App\Actions\Payment\SettleBookingPayment;
use App\Actions\Payment\StartBookingPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SandboxPaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Customer-side online payment (SLO-40 / SLO-130). Public, like the booking flow
 * itself: the unguessable booking code (checkout) and the gateway's transaction
 * reference (sandbox page) are the access keys, so a guest with no account can pay.
 *
 * Route-bound {booking:code} and {payment:provider_ref} are tenant-scoped
 * (BelongsToTenant → a cross-tenant code/reference 404s).
 */
class PaymentController extends Controller
{
    /**
     * Open a checkout for a booking waiting on payment and send the customer to the
     * gateway. A booking that is no longer waiting (already paid, expired, or paid
     * in another tab) is bounced to its confirmation page rather than charged twice.
     */
    public function checkout(Request $request, string $tenant, Booking $booking, StartBookingPayment $startPayment): RedirectResponse
    {
        if ($booking->status !== BookingStatus::PendingPayment) {
            return redirect('/booked/'.$booking->code);
        }

        // Absolute return URL: a real gateway redirects the customer from its own
        // domain, so a relative path would resolve against the wrong host.
        $session = $startPayment($booking, $request->getSchemeAndHttpHost().'/booked/'.$booking->code);

        // The sandbox checkout lives in-app (relative URL); a real gateway hands
        // back an absolute one on its own domain.
        return Str::startsWith($session->redirectUrl, '/')
            ? redirect($session->redirectUrl)
            : redirect()->away($session->redirectUrl);
    }

    /**
     * The sandbox gateway's own checkout page (SLO-130) — the "pay / decline"
     * screen that makes the flow demoable without a merchant account. Enabled by
     * `payments.sandbox.enabled`, which is off in production.
     */
    public function sandbox(string $tenant, Payment $payment): Response|RedirectResponse
    {
        abort_unless((bool) config('payments.sandbox.enabled'), 404);
        abort_unless($payment->provider === PaymentProvider::Sandbox, 404);

        $payment->load('booking.service:id,name');
        $booking = $payment->booking;

        // A settled attempt has nothing left to decide.
        if (! $payment->status->isOpen() || $booking === null) {
            return redirect($booking === null ? '/' : '/booked/'.$booking->code);
        }

        return Inertia::render('Tenant/SandboxCheckout', [
            'payment' => [
                'reference' => $payment->provider_ref,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
            ],
            'booking' => [
                'code' => $booking->code,
                'service' => $booking->service?->name,
            ],
        ]);
    }

    /**
     * Apply the outcome picked on the sandbox checkout page. This is the customer's
     * browser leg; the gateway's own callback (webhook) settles the same payment
     * through the same actions, and both are idempotent — whichever lands first wins
     * and the other is a no-op.
     */
    public function sandboxOutcome(
        SandboxPaymentRequest $request,
        string $tenant,
        Payment $payment,
        SettleBookingPayment $settlePayment,
        FailBookingPayment $failPayment,
    ): RedirectResponse {
        abort_unless((bool) config('payments.sandbox.enabled'), 404);
        abort_unless($payment->provider === PaymentProvider::Sandbox, 404);

        $booking = $payment->booking;
        abort_if($booking === null, 404);

        $payload = ['reference' => $payment->provider_ref, 'status' => $request->outcome()->value];

        if ($request->outcome() === PaymentStatus::Paid) {
            $settlePayment($payment, $payload);
        } else {
            $failPayment($payment, $payload);
        }

        return redirect('/booked/'.$booking->code);
    }

    /**
     * Gateway callback. Authenticated by the gateway's own signature (never by
     * session/CSRF), idempotent on the provider reference, and deliberately outside
     * `ensure.tenant.active`: a payment made just before a tenant was suspended must
     * still be recorded (the SLO-120 pattern).
     */
    public function webhook(Request $request, string $tenant, string $provider, PaymentGatewayManager $gateways, SettleBookingPayment $settlePayment, FailBookingPayment $failPayment): JsonResponse
    {
        $providerEnum = PaymentProvider::tryFrom($provider);
        abort_if($providerEnum === null, 404);

        try {
            $gateway = $gateways->for($providerEnum);
        } catch (RuntimeException) {
            // A provider with no adapter (yet) — nothing can verify this callback.
            abort(404);
        }

        $result = $gateway->parseWebhook($request);
        // Unverifiable body: refuse it. A forged callback must never be read as a
        // payment outcome, so this is a 400, not a "failed payment".
        abort_if($result === null, 400);

        $payment = Payment::query()
            ->where('provider', $providerEnum->value)
            ->where('provider_ref', $result->providerRef)
            ->first();
        // Tenant-scoped lookup: a reference from another tenant's gateway account
        // 404s like any cross-tenant id (docs/01).
        abort_if($payment === null, 404);

        if ($result->status === PaymentStatus::Paid) {
            $settlePayment($payment, $result->payload);
        } else {
            $failPayment($payment, $result->payload);
        }

        return response()->json(['status' => 'ok']);
    }
}
