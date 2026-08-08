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
    // Without this the Reverb socket (SLO-117) is blocked by connect-src on
    // every page that listens for live bookings.
    $policy = (new ContentSecurityPolicy(websocket: 'wss://rt.slot4u.hu:443'))->build();

    expect($policy)->toContain("connect-src 'self' wss://rt.slot4u.hu:443");
});

it('accepts extra origins from configuration', function () {
    $policy = (new ContentSecurityPolicy(extra: [
        'script' => 'https://cdn.example.test, https://pay.example.test',
        'img' => 'https://images.example.test',
    ]))->build();

    expect($policy)->toContain('https://cdn.example.test https://pay.example.test')
        ->and($policy)->toContain('https://images.example.test');
});
