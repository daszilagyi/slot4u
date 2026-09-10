<?php

/*
|--------------------------------------------------------------------------
| Fake edge + app for the deploy smoke test (SLO-162)
|--------------------------------------------------------------------------
|
| A router script for `php -S`, driven entirely by environment variables, that
| impersonates what deploy/smoke.sh meets on the public internet: our own
| application on a good day, and Cloudflare's bot protection standing in front of
| it on a bad one.
|
| It exists because the thing under test is a shell script whose whole job is
| reading real HTTP answers. Stubbing curl would only test the stub; the bug this
| covers — a challenge page passing as a healthy app because both answer 200 —
| lives precisely in the gap between a status code and a response.
|
| Scenarios (SMOKE_FAKE_SCENARIO):
|   healthy         — the application answers, security headers and all
|   challenge       — Cloudflare's managed challenge, announced by its header
|   challenge_body  — the same interstitial with no telltale header at all
|   challenge_title — the static block page, recognisable only by its title
|   parked          — a 200 HTML page that is simply not us (parked domain)
|   edge_requires_token
|                   — the app, but only for a caller presenting the smoke
|                     test's own header; anything else is challenged. Green here
|                     means every single request carried the header the WAF skip
|                     rule keys on, not just the /_deploy/health one.
|
*/

$scenario = getenv('SMOKE_FAKE_SCENARIO') ?: 'healthy';
$token = (string) getenv('SMOKE_FAKE_TOKEN');
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$presented = (string) ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');

$challengeBody = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf8"><title>Just a moment...</title></head>
<body><div id="cf-wrapper">
<script src="/cdn-cgi/challenge-platform/h/b/scripts/jsd/main.js"></script>
<script>(function(){window._cf_chl_opt={cvId:'3'};setTimeout(function(){location.reload()},3000);})();</script>
</div></body></html>
HTML;

$blockPageBody = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf8"><title>Just a moment...</title></head>
<body><h1>Checking your browser before accessing slot4u.hu</h1></body></html>
HTML;

// The edge terminates the request itself: 200, HTML, and — the point of the
// exercise — none of the application's own response headers.
$serveChallenge = function (string $body, bool $announced): void {
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Server: cloudflare');
    header('CF-RAY: 8f2c1d0e4a7b0000-BUD');
    if ($announced) {
        header('cf-mitigated: challenge');
    }
    echo $body;
};

// SecurityHeaders (SLO-145) is prepended to the global middleware stack, so the
// real application stamps a CSP on every response it produces — which is exactly
// the signature the smoke test now demands as proof of authorship.
$appHeaders = function (): void {
    header("Content-Security-Policy: default-src 'self'");
    header('X-Content-Type-Options: nosniff');
};

if ($scenario === 'challenge') {
    $serveChallenge($challengeBody, true);

    return;
}

if ($scenario === 'challenge_body') {
    $serveChallenge($challengeBody, false);

    return;
}

if ($scenario === 'challenge_title') {
    $serveChallenge($blockPageBody, false);

    return;
}

if ($scenario === 'parked') {
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><title>slot4u.hu</title></head><body>Coming soon</body></html>';

    return;
}

if ($scenario === 'edge_requires_token' && $presented !== $token) {
    $serveChallenge($challengeBody, true);

    return;
}

// --- The application ------------------------------------------------------

if ($path === '/up') {
    $appHeaders();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<html><body>Application up</body></html>';

    return;
}

if ($path === '/_deploy/health') {
    // Token-gated, and a 404 rather than a 403 when the token is wrong — the
    // route does not confirm its own existence to a caller without the secret.
    if ($token === '' || $presented !== $token) {
        http_response_code(404);
        $appHeaders();
        echo 'Not Found';

        return;
    }

    $appHeaders();
    header('Content-Type: application/json');
    echo json_encode([
        'release' => getenv('SMOKE_FAKE_RELEASE') ?: 'v9.9.9-TEST',
        'commit' => getenv('SMOKE_FAKE_COMMIT') ?: null,
        'environment' => getenv('SMOKE_FAKE_ENVIRONMENT') ?: 'production',
        'config_cached' => (getenv('SMOKE_FAKE_CONFIG_CACHED') ?: 'true') === 'true',
        'pending_migrations' => (int) (getenv('SMOKE_FAKE_PENDING') ?: '0'),
        // What the release believes about server rendering, and whether the
        // renderer answered (SLO-212). Both overridable, because the failures
        // worth testing are the combinations: enabled but unhealthy, and
        // enabled but the page still came out empty.
        'ssr_enabled' => (getenv('SMOKE_FAKE_SSR_ENABLED') ?: 'true') === 'true',
        'ssr_healthy' => (getenv('SMOKE_FAKE_SSR_HEALTHY') ?: 'true') === 'true',
    ]);

    return;
}

if ($path === '/') {
    $appHeaders();
    header('Content-Type: text/html; charset=UTF-8');

    // ⚠️ The props block is here even when the page is NOT server-rendered, and
    // that is the point: Inertia serialises the whole page into it, headings
    // included. A check that greps the raw body for `<h1` would pass on this
    // shell, which is exactly the failure SLO-212 was — a page that looks fine
    // to every automated eye and carries no markup for a search engine.
    // ⚠️ A LITERAL `<h1>` inside the props. Real Inertia escapes `<` in this
    // block, so production would not look like this today — which is precisely
    // why the fixture does: the check must not depend on that escaping staying
    // true. Strip the block and the page has no heading; grep the raw body and
    // it appears to have one.
    $props = '<script data-page="app" type="application/json">'
        .'{"component":"Welcome","props":{"heading":"<h1>only in the props</h1>"}}'
        .'</script>';

    $rendered = (getenv('SMOKE_FAKE_SSR_RENDERED') ?: 'true') === 'true'
        ? '<h1>Online foglalás</h1><p>Foglalás</p>'
        : '';

    echo '<!DOCTYPE html><html lang="hu"><head><title>slot4u</title></head>'
        .'<body><div id="app">'.$rendered.'</div>'.$props.'</body></html>';

    return;
}

http_response_code(404);
$appHeaders();
echo 'Not Found';
