<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ContentSecurityPolicy;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A tenant-admin of an active tenant, for the authenticated surfaces. */
function secAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

// --- The headers themselves ---

it('sends the security headers on the public surface', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get(tenantHost('acme', '/login'))->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull();
});

it('sends them on the admin panel too', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = secAdmin($tenant);
    app(TenantManager::class)->forget();

    $response = $this->actingAs($admin)->get(tenantHost('acme', '/dashboard'))->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
});

it('sends them on the superadmin panel too', function () {
    $super = superAdmin();

    $response = $this->actingAs($super)->get(superUrl('/'))->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull();
});

it('names a nonce in the policy and puts the same one on the inline script', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get(tenantHost('acme', '/login'))->assertOk();

    $policy = (string) $response->headers->get('Content-Security-Policy');
    expect($policy)->toMatch('/script-src [^;]*\'nonce-[A-Za-z0-9+\/=]+\'/');

    // The page has to be able to run its own inline script, or the theme switch
    // (and with it the first paint) dies under the policy.
    preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $policy, $matches);
    expect($response->getContent())->toContain('nonce="'.$matches[1].'"');
});

it('never sends HSTS over plain http', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    // A max-age pinned from a http dev host would make it unreachable for a year.
    $this->get(tenantHost('acme', '/login'))
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends HSTS over https', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get('https://acme.'.config('tenancy.central_domain').'/login')->assertOk();

    expect($response->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=')
        ->toContain('includeSubDomains');
});

it('can be switched off for a deployment that terminates policy at the edge', function () {
    config()->set('security.csp.enabled', false);
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    // The rest of the headers stay: only the policy is delegated.
    $this->get(tenantHost('acme', '/login'))
        ->assertOk()
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

// --- The policy builder (both branches, no dev server needed) ---

it('keeps a built bundle free of unsafe-eval and any dev origin', function () {
    $policy = (new ContentSecurityPolicy(nonce: 'abc123', hot: false))->build();

    expect($policy)->toContain("script-src 'self' 'nonce-abc123'")
        ->and($policy)->not->toContain('unsafe-eval')
        ->and($policy)->not->toContain('localhost:5173')
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->toContain("base-uri 'self'")
        ->and($policy)->toContain("form-action 'self'")
        ->and($policy)->toContain("frame-ancestors 'none'");
});

it('widens the policy only while the dev server is hot', function () {
    // React Refresh compiles with eval and the HMR client comes from the dev
    // origin; both are impossible with a built bundle, which is why this widening
    // is gated on `hot` rather than on an environment name.
    $policy = (new ContentSecurityPolicy(
        nonce: 'abc123',
        hot: true,
        devServer: 'http://localhost:5173',
    ))->build();

    expect($policy)->toContain("'unsafe-eval'")
        ->and($policy)->toContain('http://localhost:5173')
        ->and($policy)->toContain('ws://localhost:5173');
});

it('lets the realtime connection through', function () {
    // Without this the websocket (SLO-117) is blocked by connect-src on every
    // page that listens for live bookings.
    $policy = (new ContentSecurityPolicy(websocket: 'wss://rt.slot4u.hu:443'))->build();

    expect($policy)->toContain("connect-src 'self' wss://rt.slot4u.hu:443");
});

it('names the Reverb socket when Reverb is the active driver', function () {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.options', [
        'host' => 'rt.slot4u.test', 'port' => 8080, 'scheme' => 'http',
    ]);
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get(tenantHost('acme', '/login'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('ws://rt.slot4u.test:8080');
});

it('names the Pusher CLIENT socket when Pusher is the active driver', function () {
    // Production runs hosted Pusher while dev runs Reverb, so the policy has to
    // follow the active connection — naming a driver in code shipped a CSP that
    // would have blocked the live feed in prod (SLO-150).
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher.options', [
        'cluster' => 'eu',
        // The configured host is the REST endpoint; the browser must not be
        // pointed at it.
        'host' => 'api-eu.pusher.com',
        'port' => 443,
        'scheme' => 'https',
    ]);
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get(tenantHost('acme', '/login'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('wss://ws-eu.pusher.com')
        ->not->toContain('api-eu.pusher.com');
});

it('adds no socket origin when broadcasting is off', function () {
    config()->set('broadcasting.default', 'null');
    config()->set('broadcasting.connections.reverb.options.host', 'rt.slot4u.test');
    config()->set('broadcasting.connections.pusher.options.cluster', 'eu');
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get(tenantHost('acme', '/login'))->assertOk();

    // Neither configured driver leaks in when neither is the active one. Asserted
    // as absence rather than an exact string: the dev server widens connect-src
    // when Vite is hot, and this must hold either way.
    expect($response->headers->get('Content-Security-Policy'))
        ->not->toContain('pusher.com')
        ->not->toContain('rt.slot4u.test');
});

it('lets browser error reports through to the Sentry ingest host', function () {
    // Same failure mode as SLO-150, one integration later: a policy that looks
    // correct silently blocks the reports, and the one error that could never be
    // reported is "reporting is broken".
    config()->set('monitoring.browser_dsn', 'https://publickey@o4507.ingest.de.sentry.io/4508');
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get(tenantHost('acme', '/login'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('https://o4507.ingest.de.sentry.io')
        // The DSN's public key is not a secret, but it has no business in a
        // response header either.
        ->not->toContain('publickey');
});

it('falls back to the backend DSN for the ingest origin', function () {
    // Both projects live in one Sentry organisation and therefore share an
    // ingest host, so a host that only set the server DSN still gets a policy
    // that would admit browser reports.
    config()->set('monitoring.browser_dsn', '');
    config()->set('sentry.dsn', 'https://publickey@o4507.ingest.de.sentry.io/4509');
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get(tenantHost('acme', '/login'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('https://o4507.ingest.de.sentry.io');
});

it('adds no error reporting origin when Sentry is not configured', function () {
    config()->set('monitoring.browser_dsn', '');
    config()->set('sentry.dsn', null);
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->get(tenantHost('acme', '/login'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->not->toContain('sentry.io');
});

it('accepts extra origins from configuration', function () {
    $policy = (new ContentSecurityPolicy(extra: [
        'script' => 'https://cdn.example.test, https://pay.example.test',
        'img' => 'https://images.example.test',
    ]))->build();

    expect($policy)->toContain('https://cdn.example.test https://pay.example.test')
        ->and($policy)->toContain('https://images.example.test');
});
