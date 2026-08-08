<?php

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Rate limiting on the unauthenticated tenant surface (SLO-147, docs/01).
 *
 * The interesting property is not "there is a limit" but *whose* limit it is.
 * A bare `throttle:60,1` keys on the caller alone, so on a multi-tenant app one
 * tenant's visitors share a bucket with every other tenant's: a burst aimed at
 * one booking page would lock out an unrelated tenant's customers behind the
 * same NAT. These tests pin the per-tenant keying, not just the number.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    RateLimiter::clear('public');
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

it('answers 429 once a visitor exhausts the public allowance', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    // 60 per minute; the 61st is refused.
    foreach (range(1, 60) as $i) {
        $this->get(tenantHost('acme', '/book'))->assertOk();
    }

    $this->get(tenantHost('acme', '/book'))->assertStatus(429);
});

it('gives each tenant its own bucket', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->forget();

    // Spend one tenant's whole allowance from this address...
    foreach (range(1, 60) as $i) {
        $this->get(tenantHost('acme', '/book'))->assertOk();
    }
    $this->get(tenantHost('acme', '/book'))->assertStatus(429);

    // ...the other tenant's visitors must be unaffected. Before SLO-147 this was
    // a 429: the bucket was keyed on the caller alone.
    $this->get(tenantHost('other', '/book'))->assertOk();
});

it('gives each visitor of a tenant its own bucket', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    foreach (range(1, 60) as $i) {
        $this->get(tenantHost('acme', '/book'))->assertOk();
    }
    $this->get(tenantHost('acme', '/book'))->assertStatus(429);

    // A different address is a different bucket — the limit is not a global cap
    // on the tenant, which an attacker could spend to deny its real customers.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get(tenantHost('acme', '/book'))
        ->assertOk();
});

it('keeps every unauthenticated tenant route behind a named limiter', function () {
    // A new public route must not slip in unthrottled. Authenticated routes are
    // excluded: they are behind a session, and the auth endpoints have their own
    // Fortify limiters.
    $unthrottled = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->getDomain(), '{tenant}'))
        ->reject(fn ($route) => in_array('auth', $route->gatherMiddleware(), true))
        ->reject(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))
        ->map(fn ($route) => collect($route->methods())->first().' '.$route->uri())
        ->values()
        ->all();

    // No exceptions: every unauthenticated tenant route carries a named limiter,
    // so a new public endpoint cannot ship unthrottled by omission.
    expect($unthrottled)->toBe([]);
});
