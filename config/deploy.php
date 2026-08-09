<?php

use App\Support\Release;

return [

    /*
    |--------------------------------------------------------------------------
    | Released version (SLO-152)
    |--------------------------------------------------------------------------
    |
    | What the deploy pipeline last put on this server — see {@see Release}, which
    | reads the `.release` file `deploy/deploy.sh` writes. Sentry tags its events
    | with the same value (SLO-153), so a stack trace names a release the deploy
    | log can be searched for.
    |
    | Reading a file here is safe precisely because config files run once — at
    | cache build time on a deployed host, at boot on a dev host. Null means
    | "nothing deployed this tree", which is the normal state locally and in CI.
    |
    */

    'release' => env('APP_RELEASE') ?: Release::current(),

    // The commit that release resolved to. This is what the post-deploy smoke
    // test actually verifies: a ref name would match even when the server
    // shipped an older commit under the same name (SLO-158).
    'commit' => env('APP_RELEASE_COMMIT') ?: Release::commit(),

    /*
    |--------------------------------------------------------------------------
    | Deploy health token
    |--------------------------------------------------------------------------
    |
    | Shared secret for GET /_deploy/health, the endpoint the post-deploy smoke
    | test asks "which release is actually serving, and is the schema current?".
    |
    | It is token-gated rather than public because the answer names the exact
    | version running (docs/01 OWASP table, A01) — useful to an attacker matching
    | a known advisory against us, useless to a visitor. Without a valid token the
    | route 404s, so its existence is not confirmed either. Empty (the default)
    | means no caller can ever pass, which is the correct posture for a host that
    | never set one.
    |
    */

    'health_token' => (string) env('DEPLOY_HEALTH_TOKEN', ''),

];
