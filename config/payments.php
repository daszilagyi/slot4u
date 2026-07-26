<?php

use App\Enums\PaymentProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | Which gateway drives the customer checkout flow (SLO-130). Only `sandbox`
    | ships today — the self-hosted test provider that makes the whole
    | pending_payment → confirmed path demoable without an external account. The
    | Barion/Stripe adapters (SLO-55 / SLO-39) plug into the same contract.
    |
    */

    'default' => env('PAYMENTS_PROVIDER', PaymentProvider::Sandbox->value),

    /*
    |--------------------------------------------------------------------------
    | Sandbox gateway
    |--------------------------------------------------------------------------
    |
    | The sandbox checkout is a real page a customer could reach, so it stays off
    | in production unless deliberately enabled. Its webhook payloads are signed
    | with `secret` (HMAC-SHA256) exactly like a real gateway's, so the webhook
    | endpoint is exercised by the same signature-verification code path.
    |
    */

    'sandbox' => [
        'enabled' => (bool) env('PAYMENTS_SANDBOX_ENABLED', env('APP_ENV') !== 'production'),
        'secret' => env('PAYMENTS_SANDBOX_SECRET', env('APP_KEY', 'sandbox-secret')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment hold
    |--------------------------------------------------------------------------
    |
    | How long a pending_payment booking holds its slot before the expiry sweep
    | releases it (docs/04). Per-tenant override: `settings.payment_hold_minutes`.
    |
    */

    'hold_minutes' => (int) env('PAYMENTS_HOLD_MINUTES', 30),

];
