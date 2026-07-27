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

];
