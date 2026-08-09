<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Released version (SLO-152)
    |--------------------------------------------------------------------------
    |
    | What the deploy pipeline last put on this server. `deploy/deploy.sh` writes
    | the tag into the `.release` file (gitignored) before it rebuilds the config
    | cache, so the value is baked into the cached config and costs no disk read
    | per request in production.
    |
    | Reading a file here is safe precisely because config files run once — at
    | cache build time on a deployed host, at boot on a dev host. Null means
    | "nothing deployed this tree", which is the normal state locally and in CI.
    |
    */

    'release' => env('APP_RELEASE') ?: (static function (): ?string {
        $file = dirname(__DIR__).'/.release';

        if (! is_readable($file)) {
            return null;
        }

        $release = trim((string) file_get_contents($file));

        return $release === '' ? null : $release;
    })(),

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
