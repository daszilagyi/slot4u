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
    | ⚠️ Nothing in the app is gated by this yet, and that is not an oversight.
    | slot4u sets only strictly necessary cookies today (session + XSRF), and
    | under the ePrivacy rules those need no consent at all. What this exists for
    | is the moment analytics arrives (docs/08: Meta Pixel / GA4 are Phase 2) —
    | so it lands behind a decision that already exists, rather than shipping the
    | tag first and the banner second.
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
