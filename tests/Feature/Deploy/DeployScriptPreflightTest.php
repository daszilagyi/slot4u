<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Deploy preflight — the refusals (SLO-212)
|--------------------------------------------------------------------------
|
| deploy/deploy.sh refuses some deploys before the maintenance window opens, and
| those refusals are the only thing standing between a misconfigured server and
| a site that looks perfectly healthy while shipping empty pages. They are
| exercised for real here: the script runs, against a throwaway directory
| standing in for the server.
|
| Only the refusals. A preflight that PASSES goes on to check out a git ref and
| run composer, which is not something a test may do — so the "must not refuse"
| cases assert on the absence of that particular complaint instead, and let the
| script fail afterwards on its own terms.
|
*/

/**
 * Run the preflight against a server directory containing the given .env.
 *
 * @param  string|null  $htaccess  the docroot .htaccess, or null for no such file
 */
function runDeployPreflight(string $dotenv, ?string $htaccess = null): ProcessResult
{
    $root = sys_get_temp_dir().'/slot4u-preflight-'.bin2hex(random_bytes(6));
    $app = $root.'/slot4u';

    mkdir($app, 0o755, true);
    mkdir($root.'/public_html', 0o755, true);
    file_put_contents($app.'/.env', $dotenv);

    if ($htaccess !== null) {
        file_put_contents($root.'/public_html/.htaccess', $htaccess);
    }

    // Stubs for the two binaries the preflight insists on. It only checks that
    // they are there and executable; what they do is never reached.
    foreach (['php', 'composer'] as $binary) {
        file_put_contents($root.'/'.$binary, "#!/bin/sh\nexit 0\n");
        chmod($root.'/'.$binary, 0o755);
    }

    try {
        return Process::env([
            'HOME' => $root,
            'DEPLOY_PATH' => $app,
            'DEPLOY_PHP' => $root.'/php',
            'DEPLOY_COMPOSER' => $root.'/composer',
            'DEPLOY_DOCROOT' => $root.'/public_html',
            'DEPLOY_SSR_PATH' => $root.'/ssr',
        ])->run(['bash', base_path('deploy/deploy.sh'), 'v9.9.9-TEST']);
    } finally {
        Process::run(['rm', '-rf', $root]);
    }
}

it('refuses when server rendering is on and nothing says where the renderer is', function () {
    // ⚠️ INERTIA_SSR_ENABLED defaults to TRUE, so an .env that never mentions
    // SSR is an .env with server rendering on — and the config default URL is a
    // TCP port that nothing listens on here. Left to run, the deploy succeeds
    // and every public page quietly loses its markup.
    $result = runDeployPreflight("APP_ENV=production\n");

    expect($result->exitCode())->toBe(1);
    expect($result->errorOutput())->toContain('INERTIA_SSR_URL is not set');
});

it('⚠️ refuses to publish an unauthenticated renderer', function () {
    // The mount lives inside the public site. Without the secret, `/render` will
    // turn anyone's props into HTML served from our own domain.
    $result = runDeployPreflight(
        "INERTIA_SSR_URL=https://slot4u.hu/_ssr\nSSR_SHARED_SECRET=\n"
        ."INERTIA_SSR_ENSURE_BUNDLE_EXISTS=false\n"
    );

    expect($result->exitCode())->toBe(1);
    expect($result->errorOutput())
        ->toContain('SSR_SHARED_SECRET is empty')
        ->toContain('slot4u.hu');
});

it('does not ask for a secret when the renderer is on loopback', function () {
    // Where nothing outside the machine can call it, the secret protects
    // nothing — and demanding it would make the docker setup undeployable for
    // no reason.
    $result = runDeployPreflight(
        "INERTIA_SSR_URL=http://127.0.0.1:13714\nSSR_SHARED_SECRET=\n"
        ."INERTIA_SSR_ENSURE_BUNDLE_EXISTS=false\n"
    );

    expect($result->errorOutput())->not->toContain('SSR_SHARED_SECRET is empty');
});

it('refuses when the docroot would swallow the renderer', function () {
    // The front-controller rewrite sends every unmatched path to Laravel, which
    // 404s the renderer's sub-paths — and Inertia turns that into a silent
    // client-side fallback. The exception has to come first.
    $result = runDeployPreflight(
        "INERTIA_SSR_URL=https://slot4u.hu/_ssr\nSSR_SHARED_SECRET=sekrit\n"
        ."INERTIA_SSR_ENSURE_BUNDLE_EXISTS=false\n",
        "RewriteEngine On\nRewriteRule ^ index.php [L]\n",
    );

    expect($result->exitCode())->toBe(1);
    expect($result->errorOutput())
        ->toContain('no /_ssr exception')
        ->toContain('RewriteCond');
});

it('⚠️ says nothing about any of it when the release has SSR switched off', function () {
    // A deliberate choice must not be second-guessed by the deploy. Nothing here
    // is set, and none of the three refusals above may fire.
    $result = runDeployPreflight("INERTIA_SSR_ENABLED=false\n");

    expect($result->errorOutput())
        ->not->toContain('INERTIA_SSR_URL')
        ->not->toContain('SSR_SHARED_SECRET')
        ->not->toContain('_ssr exception');
});

it('⚠️ refuses when Inertia would look for a bundle that cannot be there', function () {
    // The gap that would have made every other precaution in this deploy
    // pointless. Inertia checks `base_path('bootstrap/ssr/ssr.js')` BEFORE it
    // calls the renderer and returns null if it is missing — and on this host
    // the bundle lives in the renderer's own directory, which is not the
    // application, while `/bootstrap/ssr` is gitignored so no checkout supplies
    // one. Bundle shipped, renderer answering, secret matching, and the page
    // still goes out empty without a word.
    $result = runDeployPreflight(
        "INERTIA_SSR_URL=https://slot4u.hu/_ssr\nSSR_SHARED_SECRET=sekrit\n"
    );

    expect($result->exitCode())->toBe(1);
    expect($result->errorOutput())
        ->toContain('no bootstrap/ssr/ssr.js')
        ->toContain('INERTIA_SSR_ENSURE_BUNDLE_EXISTS=false');
});
