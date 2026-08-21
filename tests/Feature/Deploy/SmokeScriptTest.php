<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Post-deploy smoke test — the script itself (SLO-162)
|--------------------------------------------------------------------------
|
| deploy/smoke.sh is the last gate before a deploy is called a success, so its
| own failure modes matter as much as the app's. It is exercised here for real:
| a `php -S` process plays the far end (tests/Fixtures/deploy-smoke-server.php),
| and the script talks to it over a socket with the same curl it uses against
| production.
|
| The bug that prompted this: Cloudflare served the GitHub Actions runner a bot
| challenge, and the liveness check — which only read the status code — called it
| "ok", because a challenge page is a 200 too. Everything below exists to keep a
| green line from ever again meaning "something answered".
|
*/

/** The shared secret; the fake edge and the script must agree on it. */
const SMOKE_TOKEN = 'smoke-test-token-0123456789';

const SMOKE_RELEASE = 'v9.9.9-TEST';

const SMOKE_COMMIT = '1234567890abcdef1234567890abcdef12345678';

/** A port nothing else holds. Bound and released, so the server can claim it. */
function freeLocalPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        throw new RuntimeException("could not reserve a local port: {$errstr} ({$errno})");
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * Run deploy/smoke.sh against the fake edge in the given scenario.
 *
 * @param  array<string, string>  $env  overrides for the fake edge
 * @param  array<int, string>  $args  arguments after the base URL
 */
function runSmokeScript(string $scenario, array $env = [], array $args = [SMOKE_RELEASE, SMOKE_COMMIT]): ProcessResult
{
    $port = freeLocalPort();

    $server = Process::env([
        'SMOKE_FAKE_SCENARIO' => $scenario,
        'SMOKE_FAKE_TOKEN' => SMOKE_TOKEN,
        'SMOKE_FAKE_RELEASE' => SMOKE_RELEASE,
        'SMOKE_FAKE_COMMIT' => SMOKE_COMMIT,
        ...$env,
    ])->start([
        PHP_BINARY, '-S', '127.0.0.1:'.$port, base_path('tests/Fixtures/deploy-smoke-server.php'),
    ]);

    try {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe !== false) {
                fclose($probe);
                break;
            }
            usleep(50_000);
        }

        return Process::path(base_path())
            ->env([
                'DEPLOY_HEALTH_TOKEN' => SMOKE_TOKEN,
                // One attempt, no waiting: every scenario here is decided by the
                // first answer, and a retry loop would only add seconds.
                'SMOKE_RETRIES' => '1',
                'SMOKE_DELAY' => '0',
            ])
            ->timeout(60)
            ->run(['bash', 'deploy/smoke.sh', 'http://127.0.0.1:'.$port, ...$args]);
    } finally {
        $server->stop();
    }
}

it('passes against an application that is really serving the expected release', function () {
    $result = runSmokeScript('healthy');

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    expect($result->output())
        ->toContain('health endpoint returned 200 from the application')
        ->toContain('serving '.SMOKE_RELEASE)
        ->toContain('serving commit 1234567')
        ->toContain('no pending migrations')
        ->toContain('security headers present')
        ->toContain('Smoke test passed');
});

it('fails, and says why, when the edge answers with a bot-protection challenge', function (string $scenario) {
    // The regression itself. All three disguises answer 200 with an HTML body;
    // the old liveness check would have printed "ok" for every one of them.
    $result = runSmokeScript($scenario);

    expect($result->exitCode())->toBe(1);
    expect($result->output())
        ->toContain('bot-protection challenge')
        // Named as an edge decision, not as a broken deploy: the misleading
        // message is what cost SLO-158 a round of investigation.
        ->toContain('This says nothing about the deploy')
        // And it names the fix, which is a Cloudflare rule rather than code.
        ->toContain('x-deploy-token')
        ->not->toContain('health endpoint returned 200 from the application');
})->with(['challenge', 'challenge_body', 'challenge_title']);

it('fails when a 200 comes from something other than the application', function () {
    // A parked page or an edge error page: no challenge markers at all, just a
    // page that is not ours. The proof of authorship is the security header the
    // global middleware stamps on every response (SLO-145).
    $result = runSmokeScript('parked');

    expect($result->exitCode())->toBe(1);
    expect($result->output())
        ->toContain("without the app's security headers")
        ->toContain('not the slot4u app answering')
        ->not->toContain('health endpoint returned 200 from the application');
});

it('presents its identifying header on every request, not only on the health call', function () {
    // What makes a Cloudflare WAF "skip" rule possible: the rule matches this
    // header, so the smoke test keeps measuring through the edge instead of
    // being routed around it. Here the fake edge challenges anything that
    // arrives without it — so a pass means all three requests carried it.
    $result = runSmokeScript('edge_requires_token');

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    expect($result->output())->toContain('Smoke test passed');
});

it('still catches a deploy that shipped a different commit under the expected name', function () {
    // SLO-158's check, guarded against this rewrite: the release name matches,
    // and only the commit gives the stale deploy away.
    $result = runSmokeScript('healthy', ['SMOKE_FAKE_COMMIT' => str_repeat('a', 40)]);

    expect($result->exitCode())->toBe(1);
    expect($result->output())
        ->toContain('serving '.SMOKE_RELEASE)
        ->toContain('deployed a different commit under the same name');
});

it('still catches an unfinished deploy behind a healthy-looking site', function () {
    $result = runSmokeScript('healthy', [
        'SMOKE_FAKE_PENDING' => '2',
        'SMOKE_FAKE_CONFIG_CACHED' => 'false',
    ]);

    expect($result->exitCode())->toBe(1);
    expect($result->output())
        ->toContain('pending migrations: 2')
        ->toContain('config is NOT cached');
});

it('names both causes when the health endpoint 404s', function () {
    // Route missing (an older commit is serving) or a token mismatch — the
    // endpoint is deliberately indistinguishable, so the message says both.
    $result = runSmokeScript('healthy', ['SMOKE_FAKE_TOKEN' => 'a-different-token']);

    expect($result->exitCode())->toBe(1);
    expect($result->output())
        ->toContain('HTTP 404 from /_deploy/health')
        ->toContain('DEPLOY_HEALTH_TOKEN differs from the server')
        // The 404 must not be reported as "no release in the answer" on top of it.
        ->not->toContain('no release in the /_deploy/health answer');
});

it('gets the security header on /up that the liveness check treats as proof of life', function () {
    // The other half of the contract, checked against the real middleware stack:
    // the smoke test reads liveness off a CSP header, so /up must actually carry
    // one. SecurityHeaders is prepended globally rather than added to the `web`
    // group, which is what puts it on the framework's health route too.
    $this->get('http://'.config('tenancy.central_domain').'/up')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($this->get('http://'.config('tenancy.central_domain').'/up')->headers->get('Content-Security-Policy'))
        ->not->toBeNull();
});
