<?php

use App\Enums\Feature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(BasePlanSeeder::class);

    // On-the-fly routes on the tenant subdomain, gated by the feature middleware
    // behind the standard tenant chain. `feature_waitlist` is on by default on
    // the base plan; `feature_online_payment` is opt-in (off by default). A
    // dedicated Inertia probe route keeps the feature-share assertions decoupled
    // from the real app routes (which may grow auth/guards later).
    Route::middleware(['web', 'identify.tenant', 'ensure.tenant.active'])
        ->domain('{tenant}.'.config('tenancy.central_domain'))
        ->group(function () {
            Route::middleware('ensure.feature:feature_waitlist')
                ->get('/gated-default', fn () => 'ok')->name('test.gated.default');
            Route::middleware('ensure.feature:feature_online_payment')
                ->get('/gated-optin', fn () => 'ok')->name('test.gated.optin');
            Route::middleware('ensure.feature:not_a_feature')
                ->get('/gated-unknown', fn () => 'ok')->name('test.gated.unknown');
            Route::get('/inertia-probe', fn () => Inertia::render('Tenant/Home'))->name('test.probe');
        });

    // Central-domain probe (no tenant context) for the empty-features assertion.
    Route::middleware('web')
        ->domain(config('tenancy.central_domain'))
        ->get('/inertia-probe', fn () => Inertia::render('Welcome'))->name('test.probe.central');
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

function gatedUrl(string $slug, string $path): string
{
    return 'http://'.$slug.'.'.config('tenancy.central_domain').$path;
}

it('allows a route when the feature is enabled by default', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(gatedUrl('acme', '/gated-default'))->assertOk()->assertSee('ok');
});

it('blocks a route with 403 when the feature is off for the tenant', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(gatedUrl('acme', '/gated-optin'))
        ->assertStatus(403)
        ->assertSee(__('errors.feature_disabled'));
});

it('blocks a route with 403 for an unknown feature code', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(gatedUrl('acme', '/gated-unknown'))->assertStatus(403);
});

it('unblocks an opt-in route once a superadmin override enables it', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create([
        'feature_code' => Feature::OnlinePayment,
        'enabled' => true,
    ]);
    app(TenantManager::class)->forget();

    $this->get(gatedUrl('acme', '/gated-optin'))->assertOk()->assertSee('ok');
});

it('re-blocks an opt-in route when an override disables it', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create([
        'feature_code' => Feature::Waitlist,
        'enabled' => false,
    ]);
    app(TenantManager::class)->forget();

    $this->get(gatedUrl('acme', '/gated-default'))->assertStatus(403);
});

it('shares the enabled feature codes with the frontend', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create([
        'feature_code' => Feature::OnlinePayment,
        'enabled' => true,
    ]);
    app(TenantManager::class)->forget();

    $this->get(gatedUrl('acme', '/inertia-probe'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('features', fn ($codes) => $codes->contains(Feature::OnlinePayment->value)
                && $codes->contains(Feature::Reports->value)
                && ! $codes->contains(Feature::Sms->value)));
});

it('shares no features outside tenant context', function () {
    $this->get('http://'.config('tenancy.central_domain').'/inertia-probe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('features', []));
});
