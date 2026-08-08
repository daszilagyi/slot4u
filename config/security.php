<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security response headers (SLO-145)
    |--------------------------------------------------------------------------
    |
    | Sent by {@see App\Http\Middleware\SecurityHeaders} on every response. The
    | values are configuration rather than constants because a deployment may
    | legitimately need to widen them (an embedded booking widget, a CDN) without
    | a code change — but the defaults are the strict ones.
    |
    */

    'headers' => [

        // Strict-Transport-Security, seconds. Only ever sent over HTTPS, so a
        // plain-http dev host can never pin itself into an unreachable state.
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

        // The booking surface is not meant to be framed. Set to SAMEORIGIN only
        // if an embeddable widget ships (it would need frame-ancestors too).
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),

        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

        // Features the app never uses; denying them keeps a compromised script
        // from prompting for them in our name.
        'permissions_policy' => env(
            'SECURITY_PERMISSIONS_POLICY',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Built per request by {@see App\Support\ContentSecurityPolicy}. Scripts are
    | nonce-gated: the root Blade carries one inline script (the pre-paint theme
    | switch) and Vite's tags get the same nonce, so `unsafe-inline` is never
    | needed for scripts. Styles are the documented exception — Radix and the
    | toast library inject <style> elements at runtime.
    |
    | Extra origins are comma-separated so an environment can add its own
    | (analytics, a payment provider's script) without touching code.
    |
    */

    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),

        'extra' => [
            'script' => (string) env('SECURITY_CSP_SCRIPT_SRC', ''),
            'connect' => (string) env('SECURITY_CSP_CONNECT_SRC', ''),
            'img' => (string) env('SECURITY_CSP_IMG_SRC', ''),
            'frame' => (string) env('SECURITY_CSP_FRAME_SRC', ''),
        ],
    ],

];
