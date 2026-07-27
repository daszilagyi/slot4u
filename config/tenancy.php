<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central domain
    |--------------------------------------------------------------------------
    |
    | The apex domain that serves the central marketing/registration site and
    | anchors every tenant subdomain ({tenant}.{central}). Read at boot time
    | by the tenant route group, so it must be set via env, not runtime config.
    |
    */

    'central_domain' => env('APP_CENTRAL_DOMAIN', 'slot4u.test'),

    /*
    |--------------------------------------------------------------------------
    | Admin subdomain
    |--------------------------------------------------------------------------
    |
    | Subdomain of the central domain that serves the superadmin panel
    | (no tenant context).
    |
    */

    'admin_subdomain' => env('APP_ADMIN_SUBDOMAIN', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Reserved subdomains
    |--------------------------------------------------------------------------
    |
    | Labels that can never be a tenant slug. A request to one of these as a
    | tenant subdomain resolves to 404 in IdentifyTenant.
    |
    */

    'reserved_subdomains' => [
        'www', 'admin', 'app', 'api', 'mail', 'assets', 'static', 'cdn',
        // Cloudflare for SaaS fallback origin (SLO-135): every custom-hostname
        // request lands on this host, so a tenant must never own the slug.
        'customers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom domains (feature_custom_domain, SLO-42)
    |--------------------------------------------------------------------------
    |
    | `cname_target` is the host a tenant must point its own domain at with a
    | CNAME. Null means "the tenant's own {slug}.{central} subdomain", which is
    | the right answer while every tenant is served by the same origin. Set it
    | to a dedicated edge hostname (e.g. Cloudflare for SaaS fallback origin)
    | when custom-hostname TLS moves off the shared cPanel vhost.
    |
    | `resolution_ttl` caps how long a host → tenant mapping is cached. Writes
    | forget the entry explicitly; the TTL only bounds staleness after an
    | out-of-band change (a manual DB edit, a restore).
    |
    */

    'cname_target' => env('APP_CUSTOM_DOMAIN_TARGET'),

    'resolution_ttl' => (int) env('APP_CUSTOM_DOMAIN_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Original host header (SLO-135)
    |--------------------------------------------------------------------------
    |
    | Cloudflare for SaaS forwards the visitor's own hostname as the Host
    | header, which the shared-hosting vhost (*.{central}) does not match. A
    | Cloudflare Origin Rule therefore rewrites Host to the fallback origin and
    | a Transform Rule carries the real hostname in this header instead.
    |
    | Only honoured on requests from a TRUSTED PROXY (bootstrap/app.php lists
    | Cloudflare's ranges) — otherwise anyone could claim any tenant's host by
    | setting a header. Empty disables the mechanism entirely.
    |
    */

    'original_host_header' => env('APP_ORIGINAL_HOST_HEADER', 'X-Slot4u-Original-Host'),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare for SaaS (custom hostname TLS, SLO-135)
    |--------------------------------------------------------------------------
    |
    | Cloudflare only serves custom hostnames registered on the zone, so a
    | verified tenant domain has to be registered through the API before it
    | loads at all. Unset in dev/CI, where no outbound call is made and a
    | domain simply stays verified-but-unprovisioned.
    |
    | Token scope: Zone → SSL and Certificates → Edit, on this zone only.
    |
    */

    'cloudflare' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'timeout' => (int) env('CLOUDFLARE_TIMEOUT', 15),
    ],

];
