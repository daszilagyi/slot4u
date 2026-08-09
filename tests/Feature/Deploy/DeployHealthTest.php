<?php

use App\Models\Tenant;
use App\Tenancy\TenantManager;

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
        ->assertJsonPath('environment', 'testing')
        // RefreshDatabase has run every migration, so nothing is outstanding.
        ->assertJsonPath('pending_migrations', 0);
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

it('reads the release from the .release file the deploy script writes', function () {
    // The contract between deploy/deploy.sh and the application: no env edit on
    // the server, no config change — the script drops a file and rebuilds the
    // config cache. Exercised by re-evaluating the config file itself.
    $file = base_path('.release');
    $existing = is_readable($file) ? file_get_contents($file) : null;

    try {
        file_put_contents($file, "  v1.2.3-M9\n");

        expect(require config_path('deploy.php'))
            ->toHaveKey('release', 'v1.2.3-M9');
    } finally {
        $existing === null ? @unlink($file) : file_put_contents($file, $existing);
    }
})->skip(
    fn () => env('APP_RELEASE') !== null && env('APP_RELEASE') !== '',
    'APP_RELEASE overrides the file on this host.'
);
