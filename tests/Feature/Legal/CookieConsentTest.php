<?php

use App\Models\Tenant;
use App\Support\CookieConsent;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Cookie consent (SLO-165, docs/19 §11)
|--------------------------------------------------------------------------
|
| The decision is a cookie, read on the server, so a server-rendered page knows
| before it sends its first byte whether the banner belongs on it. The tests
| below are mostly about the two ways that can go quietly wrong: an undecided
| visitor being treated as having agreed, and a stored decision outliving the
| set of options it was made about.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function consentCookieValue(array $categories, ?string $version = null): string
{
    return (string) json_encode([
        'v' => $version ?? (string) config('consent.version'),
        'c' => $categories,
    ]);
}

it('tells the page nothing has been decided when there is no cookie', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/'))
        ->assertInertia(fn ($page) => $page
            ->where('consent.decided', false)
            ->where('consent.categories.analytics', false)
            ->where('consent.categories.marketing', false));
});

it('treats an undecided visitor as refusing, never as agreeing', function () {
    // The failure mode that matters: silence read as a yes.
    $consent = CookieConsent::undecided();

    expect($consent->allows('analytics'))->toBeFalse()
        ->and($consent->allows('marketing'))->toBeFalse()
        ->and($consent->decided)->toBeFalse();
});

it('records an accept-all decision and stops showing the banner', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $response = $this->from(tenantHost('acme', '/'))
        ->post(tenantHost('acme', '/cookie-consent'), [
            'analytics' => true,
            'marketing' => true,
        ]);

    $response->assertRedirect();
    $response->assertCookie(config('consent.cookie'));

    $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        consentCookieValue(['analytics' => true, 'marketing' => true]),
    )->get(tenantHost('acme', '/'))
        ->assertInertia(fn ($page) => $page
            ->where('consent.decided', true)
            ->where('consent.categories.analytics', true));
});

it('records a refusal as a decision, not as silence', function () {
    // Otherwise "no" would show the banner again on the next page, which is the
    // pattern that trains people to click accept.
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        consentCookieValue(['analytics' => false, 'marketing' => false]),
    )->get(tenantHost('acme', '/'))
        ->assertInertia(fn ($page) => $page
            ->where('consent.decided', true)
            ->where('consent.categories.analytics', false));
});

it('keeps a partial decision partial', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        consentCookieValue(['analytics' => true, 'marketing' => false]),
    )->get(tenantHost('acme', '/'))
        ->assertInertia(fn ($page) => $page
            ->where('consent.categories.analytics', true)
            ->where('consent.categories.marketing', false));
});

it('asks again when the stored decision names an older version', function () {
    // A choice made about a different set of options is not a choice about this
    // one — the same rule a legal document version follows (SLO-161).
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        consentCookieValue(['analytics' => true, 'marketing' => true], 'ancient'),
    )->get(tenantHost('acme', '/'))
        ->assertInertia(fn ($page) => $page
            ->where('consent.decided', false)
            ->where('consent.categories.analytics', false));
});

it('asks again when the cookie is not something it wrote', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->withUnencryptedCookie((string) config('consent.cookie'), 'not-json-at-all')
        ->get(tenantHost('acme', '/'))
        ->assertInertia(fn ($page) => $page->where('consent.decided', false));
});

it('ignores a category it does not know about', function () {
    // A cookie carrying `tracking: true` must not grant anything: the category
    // list is the app's, not the cookie's.
    $consent = CookieConsent::granted(['analytics' => true, 'tracking' => true]);

    expect($consent->allows('analytics'))->toBeTrue()
        ->and($consent->allows('tracking'))->toBeFalse()
        ->and($consent->toArray()['categories'])
        ->toBe(['analytics' => true, 'marketing' => false]);
});

it('accepts the decision on the central domain too', function () {
    $this->from('http://'.config('tenancy.central_domain').'/')
        ->post('http://'.config('tenancy.central_domain').'/cookie-consent', [
            'analytics' => true,
        ])
        ->assertRedirect()
        ->assertCookie(config('consent.cookie'));
});

it('takes the decision from a signed-out visitor', function () {
    // Somebody declining to be tracked cannot be asked to identify themselves
    // first, so the endpoint is open.
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->assertGuest();

    $this->from(tenantHost('acme', '/'))
        ->post(tenantHost('acme', '/cookie-consent'), ['analytics' => false])
        ->assertRedirect();
});

it('shows the banner region on a server-rendered public page', function () {
    // SSR correctness in the only way a request test can see it: the decision
    // travels with the page rather than being discovered later in the browser.
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/book'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('consent'));
});
