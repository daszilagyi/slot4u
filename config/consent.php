<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cookie consent (SLO-165, docs/19 §11)
    |--------------------------------------------------------------------------
    |
    | The visitor's decision about non-essential storage. It lives in a cookie
    | rather than in the database on purpose: the decision belongs to a browser,
    | not to a person, and writing it to a row would mean identifying an
    | anonymous visitor in order to record that they declined to be tracked.
    |
    | What it gates today: slot4u's own GA4 tag on the marketing site (SLO-172).
    | The decision is read server-side, before the root Blade decides whether to
    | emit the tag and before the CSP decides whether Google is a permitted
    | origin — so declining does not merely stop a script from running, it stops
    | the page from ever containing it. Tenant-side measurement (Meta Pixel /
    | tenant GA4) follows in SLO-56 and hangs off the same two categories.
    |
    */

    // ⚠️ Excluded from cookie encryption in bootstrap/app.php, and the name is
    // repeated there literally because that list runs before config is
    // resolvable. Change one and you must change the other — the symptom of
    // forgetting is a decision that silently stops being remembered.
    'cookie' => env('CONSENT_COOKIE', 'slot4u_consent'),

    // Roughly a year. Long enough not to nag, short enough that a decision is
    // periodically reconsidered — the usual reading of "consent is not forever".
    'lifetime_days' => (int) env('CONSENT_LIFETIME_DAYS', 365),

    /*
    | Bump when the categories change or the cookie policy is rewritten: a stored
    | decision naming an older version is treated as no decision, and the banner
    | asks again. Same rule as a legal document version (SLO-161) — a choice made
    | about a different set of options is not a choice about this one.
    */
    'version' => env('CONSENT_VERSION', '1'),

    /*
    | The categories a visitor decides about. `necessary` is not listed: it is
    | not a choice, and offering it as one would misrepresent what refusing does.
    */
    'categories' => ['analytics', 'marketing'],

];
