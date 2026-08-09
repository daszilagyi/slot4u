<?php

use App\Support\Monitoring\SentryScrubber;
use App\Support\Release;

/**
 * Sentry Laravel SDK configuration (SLO-153, docs/17-monitoring-es-riasztas.md).
 *
 * Trimmed down from the package default on purpose. Two rules shaped it:
 *
 *  1. **No DSN, no Sentry.** Without a DSN the transport skips every event before
 *     it opens a socket, so dev and CI make no outgoing call at all — the same
 *     posture as the Null custom-hostname provisioner (SLO-135).
 *  2. **No customer data leaves the building.** `send_default_pii` stays off and
 *     every event additionally passes through {@see SentryScrubber}, because the
 *     SDK's own idea of PII (IP, cookies, auth headers) is narrower than ours:
 *     a booking's guest name, email and phone are customer data too.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // What version is failing. The deploy script writes the released tag into
    // `.release` (SLO-152), so the two systems agree on one name for a release.
    'release' => env('SENTRY_RELEASE') ?: Release::current(),

    'environment' => env('SENTRY_ENVIRONMENT'),

    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    // Performance tracing off: it is the expensive half of Sentry (both in quota
    // and in what it collects — spans carry URLs and SQL), and the question this
    // issue answers is "did something break", not "was it slow". Turning it on is
    // a deliberate later decision, not a default we drifted into.
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,

    // Log/metric streaming to Sentry stays off; logs go to the app's own channel
    // and would be a second, unscrubbed path out.
    'enable_logs' => false,
    'enable_metrics' => false,

    // Never. The scrubber enforces it too, but the SDK should not collect it in
    // the first place.
    'send_default_pii' => false,

    // The request body is the single richest source of customer data in this app
    // (guest name/email/phone on every public booking). Not collected at all.
    'max_request_body_size' => 'none',

    // Every event, from any capture path, goes through the scrubber.
    'before_send' => [SentryScrubber::class, 'send'],
    'before_breadcrumb' => [SentryScrubber::class, 'breadcrumb'],

    'ignore_transactions' => [
        '/up',
    ],

    'breadcrumbs' => [
        // The trail that makes an exception diagnosable. Log messages and SQL
        // statements are scrubbed on the way out; bindings are never collected.
        'logs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
        // Cache hits are noise at this app's size.
        'cache' => false,
        'livewire' => false,
    ],

];
