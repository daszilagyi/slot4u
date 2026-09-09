<?php

declare(strict_types=1);

/**
 * The vertical landing pages (SLO-198, docs/22 §4).
 *
 * One entry = one page at `slot4u.hu/{slug}`, aimed at a single trade, with its
 * own Meta Ads group and its own SEO. The copy lives in `lang/hu/app.php` under
 * `verticals.{slug}`; this file only says which pages exist and which demo
 * tenant each one shows.
 *
 * ⚠️ The registry is what keeps `/{vertical}` from being a catch-all. The route
 * is constrained to these keys, so an unknown path still falls through to a 404
 * instead of rendering an empty page — and adding the next trade is this line
 * plus a block of copy, with no component to change (docs/22 §4, "ugyanez a
 * sablon").
 *
 * `demo_tenant` is a slug, not an id: the demo tenants are rebuilt nightly
 * (`demo:reset`, SLO-191) and come back with new ids. It may name a tenant that
 * is not seeded here — the page then renders without its live-demo section
 * rather than with a dead frame, exactly as the home page does.
 */
return [
    'autoszerviz' => [
        'demo_tenant' => 'demo-autoszerviz',
    ],
];
