<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform analytics (SLO-172)
    |--------------------------------------------------------------------------
    |
    | slot4u's OWN measurement, on slot4u's OWN marketing site. Deliberately not
    | a tenant-facing setting and deliberately never rendered on a tenant host:
    | on `{slug}.slot4u.hu` the tenant is the data controller and slot4u is only
    | the processor (docs/19 §2), so measuring that traffic into a slot4u-owned
    | GA4 property would be the platform helping itself to data it processes for
    | someone else. Tenant measurement is its own thing (SLO-56).
    |
    | ⚠️ No default. A hardcoded production id would mean every developer laptop,
    | every CI run and every future staging host reporting into the live property
    | — and the symptom (inflated, unattributable traffic) is invisible until
    | someone tries to trust a number. Set it in the production .env only:
    |
    |     ANALYTICS_GA4_MEASUREMENT_ID=G-10EPJ99W18
    |
    | Empty means the tag is never emitted, which is what dev and CI want.
    |
    */

    'platform' => [
        'ga4_measurement_id' => (string) env('ANALYTICS_GA4_MEASUREMENT_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor origins for the Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | Added to the policy (SLO-145) ONLY on a request that actually emits the
    | tag. Configuration rather than constants because Google renames hosts on
    | its own schedule, but the important part is where they are applied from:
    | {@see App\Support\Analytics\PlatformAnalytics::cspOrigins()}, per request.
    |
    | The alternative — widening SECURITY_CSP_SCRIPT_SRC in the environment —
    | would leave googletagmanager.com executable on every page of the app
    | forever, including the admin panel and the tenant booking flow, in order to
    | run a script on one marketing page. A visitor who declined analytics would
    | still be handing an injected <script src> a permitted origin to load from.
    |
    */

    'origins' => [

        'ga4' => [
            'script' => ['https://www.googletagmanager.com'],
            // gtag.js posts hits with sendBeacon/fetch, and which host it picks
            // is regional (`region1.google-analytics.com`) — hence the wildcards.
            'connect' => [
                'https://www.googletagmanager.com',
                'https://*.google-analytics.com',
                'https://*.analytics.google.com',
            ],
            // The no-JS-transport fallback is still an image request.
            'img' => [
                'https://www.googletagmanager.com',
                'https://*.google-analytics.com',
            ],
        ],

        'meta_pixel' => [
            'script' => ['https://connect.facebook.net'],
            'connect' => ['https://www.facebook.com', 'https://connect.facebook.net'],
            // fbevents.js reports by dropping a 1×1 on facebook.com, and the
            // <noscript> fallback is literally an <img>.
            'img' => ['https://www.facebook.com'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant measurement (SLO-56)
    |--------------------------------------------------------------------------
    |
    | The TENANT's own GA4 property and Meta Pixel, on the TENANT's own public
    | pages. Configured per tenant (`tenants.analytics`), never here — this only
    | says which consent category each vendor answers to.
    |
    | The split is not cosmetic. GA4 is measurement and Meta Pixel is advertising:
    | a visitor who agreed to be counted has not thereby agreed to be retargeted,
    | and the banner asks the two questions separately. Mapping both to
    | `analytics` would quietly make one answer stand for the other.
    |
    */

    'tenant' => [
        'ga4_category' => 'analytics',
        'meta_pixel_category' => 'marketing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta Conversions API (SLO-173)
    |--------------------------------------------------------------------------
    |
    | Where the server-side half of a conversion goes. The pixel id and the
    | access token are the TENANT's and live on the tenant row; only the endpoint
    | and the timeout are the platform's business.
    |
    | The Graph host is configurable so a test can point it somewhere harmless
    | without faking the HTTP client — and so a future proxy or regional endpoint
    | is an env change rather than a release.
    |
    */

    'meta' => [
        'graph_url' => (string) env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'api_version' => (string) env('META_GRAPH_VERSION', 'v21.0'),
        'timeout' => (int) env('META_CAPI_TIMEOUT', 15),
    ],

];
