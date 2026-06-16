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

];
