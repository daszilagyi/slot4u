<?php

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Deploy health endpoint (SLO-152)
|--------------------------------------------------------------------------
|
| The post-deploy smoke test's only source of truth about what is actually
| serving. If this endpoint answers when it should not, it hands an attacker the
| running version; if it stops answering when it should, every deploy fails.
|
*/

beforeEach(function () {
    config()->set('deploy.health_token', 'test-deploy-token');
    config()->set('deploy.release', 'v9.9.9-TEST');
    config()->set('deploy.commit', '1234567890abcdef1234567890abcdef12345678');
});

function healthUrl(): string
{
    return 'http://'.config('tenancy.central_domain').'/_deploy/health';
}

it('reports the release and migration state to a caller with the token', function () {
    $this->withHeader('X-Deploy-Token', 'test-deploy-token')
        ->getJson(healthUrl())
        ->assertOk()
        ->assertJsonPath('release', 'v9.9.9-TEST')
        // The commit is what the smoke test actually holds the deploy to: a ref
        // name matches even when an older commit is serving (SLO-158).
        ->assertJsonPath('commit', '1234567890abcdef1234567890abcdef12345678')
        ->assertJsonPath('environment', 'testing')
        // RefreshDatabase has run every migration, so nothing is outstanding.
        ->assertJsonPath('pending_migrations', 0);
});

it('reports a null commit on a tree that was never deployed', function () {
    // Dev and CI. Null is honest here; the smoke test only compares the commit
    // when the caller supplied one to compare against.
    config()->set('deploy.commit', null);

    $this->withHeader('X-Deploy-Token', 'test-deploy-token')
        ->getJson(healthUrl())
        ->assertOk()
        ->assertJsonPath('commit', null);
});

it('hides the endpoint from a caller without the token', function () {
    $this->getJson(healthUrl())->assertNotFound();
});

it('hides the endpoint from a caller presenting the wrong token', function () {
    $this->withHeader('X-Deploy-Token', 'not-the-token')
        ->getJson(healthUrl())
        ->assertNotFound();
});

it('stays shut on a host that configured no token at all', function () {
    // The default. An empty header must not match an empty secret — otherwise
    // every unconfigured deployment would publish its version to the internet.
    config()->set('deploy.health_token', '');

    $this->withHeader('X-Deploy-Token', '')
        ->getJson(healthUrl())
        ->assertNotFound();
});

it('answers on a tenant host too', function () {
    // The pipeline may be pointed at any host it deployed; the answer is about
    // the deployment, not the tenant.
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $this->withHeader('X-Deploy-Token', 'test-deploy-token')
        ->getJson(tenantHost('acme', '/_deploy/health'))
        ->assertOk()
        ->assertJsonPath('release', 'v9.9.9-TEST');
});

it('is throttled so the token cannot be guessed at speed', function () {
    foreach (range(1, 20) as $ignored) {
        $this->getJson(healthUrl())->assertNotFound();
    }

    $this->getJson(healthUrl())->assertStatus(429);
});

it('reads the release and the commit from the .release file the deploy script writes', function () {
    // The contract between deploy/deploy.sh and the application: no env edit on
    // the server, no config change — the script drops a two-line file and
    // rebuilds the config cache. Exercised by re-evaluating the config itself.
    $file = base_path('.release');
    $existing = is_readable($file) ? file_get_contents($file) : null;

    try {
        file_put_contents($file, "  v1.2.3-M9\n  abc1234def5678\n");

        expect(require config_path('deploy.php'))
            ->toHaveKey('release', 'v1.2.3-M9')
            ->toHaveKey('commit', 'abc1234def5678');
    } finally {
        $existing === null ? @unlink($file) : file_put_contents($file, $existing);
    }
})->skip(
    fn () => env('APP_RELEASE') !== null && env('APP_RELEASE') !== '',
    'APP_RELEASE overrides the file on this host.'
);

// --- The renderer (SLO-212) -------------------------------------------------
//
// Server rendering is the one part of a deploy that can break without anything
// going wrong: Inertia falls back to the client silently, and the page stays a
// perfectly good 200 with no markup in it. These fields are how the smoke test
// gets to see that, so they have to be right about the difference between "we
// did not ask" and "we asked and got nothing".

function ssrHealth(): TestResponse
{
    // ⚠️ A pattern that matches nothing must fail loudly. Written first with the
    // port left out of the fake, every one of these tests got the default
    // 200-with-no-body instead of the answer it meant to send — and all but one
    // still passed, proving nothing.
    Http::preventStrayRequests();

    return test()->withHeader('X-Deploy-Token', 'test-deploy-token')->getJson(healthUrl());
}

it('reports the renderer healthy when it answers that it is', function () {
    config()->set('inertia.ssr.enabled', true);
    config()->set('inertia.ssr.url', 'http://ssr.test:13714');
    Http::fake(['ssr.test:13714/health' => Http::response(['status' => 'OK', 'timestamp' => 1])]);

    ssrHealth()
        ->assertOk()
        ->assertJsonPath('ssr_enabled', true)
        ->assertJsonPath('ssr_healthy', true);
});

it('⚠️ does not call a 200 healthy when the body says there is nothing there', function () {
    // Production, exactly as found. The renderer mounted at `/_ssr` answered
    // every path with HTTP 200 and `{"status":"NOT_FOUND"}` — Passenger does not
    // strip the mount prefix, so Inertia's stock server matched no route. It
    // rendered NOTHING, and a status-code check calls that healthy: the smoke
    // test would have green-lit the very deploy this issue exists to catch.
    config()->set('inertia.ssr.enabled', true);
    config()->set('inertia.ssr.url', 'http://ssr.test:13714');
    Http::fake(['ssr.test:13714/health' => Http::response(['status' => 'NOT_FOUND', 'timestamp' => 1])]);

    ssrHealth()->assertOk()->assertJsonPath('ssr_healthy', false);
});

it('reports the renderer unhealthy when it cannot be reached at all', function () {
    config()->set('inertia.ssr.enabled', true);
    config()->set('inertia.ssr.url', 'http://ssr.test:13714');
    Http::fake(fn () => throw new ConnectionException('refused'));

    ssrHealth()->assertOk()->assertJsonPath('ssr_healthy', false);
});

it('reports the renderer unhealthy when nothing says where it is', function () {
    config()->set('inertia.ssr.enabled', true);
    config()->set('inertia.ssr.url', '');
    Http::fake();

    ssrHealth()->assertOk()->assertJsonPath('ssr_healthy', false);

    Http::assertNothingSent();
});

it('⚠️ says null rather than false when server rendering is switched off', function () {
    // "Not asked" and "asked and got nothing" must not look the same. If off
    // read as false, turning SSR off would fail every deploy, and the smoke test
    // would have to guess which one it was looking at.
    config()->set('inertia.ssr.enabled', false);
    Http::fake();

    ssrHealth()
        ->assertOk()
        ->assertJsonPath('ssr_enabled', false)
        ->assertJsonPath('ssr_healthy', null);

    Http::assertNothingSent();
});
