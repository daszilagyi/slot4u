<?php

use Illuminate\Support\Facades\Route;

/**
 * Prod runs behind Cloudflare (SLO-125). These lock in the `trustProxies`
 * config in bootstrap/app.php: a request arriving from a Cloudflare edge IP
 * must have its forwarded client IP and scheme honoured, while a request from
 * any other (untrusted) peer must not — otherwise a direct-to-origin caller
 * could spoof `X-Forwarded-For` and poison rate limiting / audit logs.
 */
beforeEach(function () {
    Route::get('/__proxy_probe', fn () => [
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
    ]);
});

it('trusts the forwarded client IP and scheme from a Cloudflare edge IP', function () {
    $response = $this->call('GET', '/__proxy_probe', server: [
        'REMOTE_ADDR' => '173.245.48.1',        // within Cloudflare's 173.245.48.0/20
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7', // the real visitor
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    $response->assertOk();
    expect($response->json('ip'))->toBe('203.0.113.7');
    expect($response->json('secure'))->toBeTrue();
});

it('ignores forwarded headers from an untrusted (non-Cloudflare) peer', function () {
    $response = $this->call('GET', '/__proxy_probe', server: [
        'REMOTE_ADDR' => '203.0.113.99',      // not a Cloudflare range
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',  // spoof attempt
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    $response->assertOk();
    expect($response->json('ip'))->toBe('203.0.113.99');
    expect($response->json('secure'))->toBeFalse();
});
