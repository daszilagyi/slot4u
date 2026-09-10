<?php

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/*
|--------------------------------------------------------------------------
| One decision, one controller (SLO-220, docs/19 §11.6)
|--------------------------------------------------------------------------
|
| The consent cookie used to be written at `Domain=.{central}`, so a visitor who
| accepted on the marketing site had, without being asked, also accepted on every
| tenant's booking page. Those are different data controllers (docs/19 §2): the
| yes given to slot4u's own GA4 was being spent on a tenant's advertising pixel.
|
| The fix is structural rather than a rule — the cookie is host-only, so the
| browser simply never carries the answer across. That makes the assertions here
| unusual: what has to be true is a property of the Set-Cookie HEADER, not of a
| page. A test client does not enforce cookie scoping, so asserting "the tenant
| page ignored the marketing decision" by replaying a cookie would prove nothing
| a browser would agree with. The Domain attribute is the thing browsers act on,
| so the Domain attribute is what these assert.
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

/**
 * ⚠️ Prefixed, and not shortened. Pest helpers declared in a test file are
 * GLOBAL functions: a plain `centralUrl()` here collides fatally with the one in
 * WelcomeTest, and the collision only shows up in a full run — never when this
 * file is run on its own.
 */
function boundaryCentralUrl(string $path = '/'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

function boundaryResponseCookie(TestResponse $response, string $name): ?SymfonyCookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}

// --- The decision does not leave the host it was made on ---

it('stores a decision made on the marketing site host-only', function () {
    $response = $this->post(boundaryCentralUrl('/cookie-consent'), ['analytics' => true])
        ->assertRedirect();

    $cookie = boundaryResponseCookie($response, (string) config('consent.cookie'));

    // A null Domain is what makes the browser send it back to slot4u.hu and to
    // nothing else. `.slot4u.hu` here would be the bug this test exists for.
    expect($cookie)->not->toBeNull()
        ->and($cookie->getDomain())->toBeNull();
});

it('stores a decision made on a tenant booking page host-only too', function () {
    // Both directions matter. A tenant's visitor answering the tenant's question
    // must not thereby answer for slot4u, or for the tenant next door.
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $response = $this->post(tenantHost('acme', '/cookie-consent'), ['marketing' => true])
        ->assertRedirect();

    $cookie = boundaryResponseCookie($response, (string) config('consent.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->getDomain())->toBeNull();
});

it('keeps the session cookie shared, because that sharing is deliberate', function () {
    // The guard against over-correcting. SESSION_DOMAIN spans the subdomains on
    // purpose, so signing in once works across a tenant's surfaces; only the
    // CONSENT cookie was wrong. A fix that narrowed both would log everyone out
    // at every hop and look, from the outside, like the same green suite.
    $response = $this->post(boundaryCentralUrl('/cookie-consent'), ['analytics' => true]);

    $session = boundaryResponseCookie($response, (string) config('session.cookie'));

    expect($session)->not->toBeNull()
        ->and($session->getDomain())->toBe((string) config('session.domain'));
});

// --- The retired cookie is not read, and is actively removed ---

it('does not read the retired domain-wide cookie as a decision', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->withUnencryptedCookie(
        (string) config('consent.shared_cookie'),
        (string) json_encode(['v' => (string) config('consent.version'), 'c' => ['analytics' => true, 'marketing' => true]]),
    )->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('consent.decided', false)
            ->where('consent.categories.analytics', false)
            ->where('consent.categories.marketing', false));
});

it('deletes the retired cookie from a browser still carrying it', function () {
    $response = $this->withUnencryptedCookie((string) config('consent.shared_cookie'), 'anything')
        ->get(boundaryCentralUrl('/'))
        ->assertOk();

    $removal = boundaryResponseCookie($response, (string) config('consent.shared_cookie'));

    // Aimed at the domain it was written to. A deletion whose Domain does not
    // match the cookie's silently deletes nothing — and looks identical in a
    // test that only asserts a header is present.
    expect($removal)->not->toBeNull()
        ->and($removal->getDomain())->toBe('.'.config('tenancy.central_domain'))
        ->and($removal->getExpiresTime())->toBeLessThan(time());
});

it('deletes it from a tenant host as well, not only from the marketing site', function () {
    // Whichever host the visitor happens to open next has to be able to do it;
    // there is no page everyone passes through.
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $response = $this->withUnencryptedCookie((string) config('consent.shared_cookie'), 'anything')
        ->get(tenantHost('acme', '/'))
        ->assertOk();

    expect(boundaryResponseCookie($response, (string) config('consent.shared_cookie')))->not->toBeNull();
});

it('sends no deletion header to a browser that never had the old cookie', function () {
    // Everyone from here on. Without this check every response on every host
    // would carry a pointless Set-Cookie, forever.
    $response = $this->get(boundaryCentralUrl('/'))->assertOk();

    expect(boundaryResponseCookie($response, (string) config('consent.shared_cookie')))->toBeNull();
});

it('still deletes it on the redirect the consent form itself returns', function () {
    // The one response where a queued cookie is easiest to lose: the POST does
    // not render a page, it redirects. Someone who answers the banner on their
    // first visit back must not keep the retired cookie because of that.
    $response = $this->withUnencryptedCookie((string) config('consent.shared_cookie'), 'anything')
        ->post(boundaryCentralUrl('/cookie-consent'), ['analytics' => false])
        ->assertRedirect();

    expect(boundaryResponseCookie($response, (string) config('consent.shared_cookie')))->not->toBeNull()
        ->and(boundaryResponseCookie($response, (string) config('consent.cookie')))->not->toBeNull();
});

// --- What the leak actually did, asserted end to end ---

it('does not load a tenant measurement tag off the retired shared decision', function () {
    // The user-visible failure: accept on slot4u.hu, walk onto a tenant's public
    // page, and the tenant's own GA4 was in the HTML without anyone having asked
    // about the tenant. The retired cookie must not be able to do that any more.
    Tenant::factory()->active()->create([
        'slug' => 'acme',
        'analytics' => ['ga4_measurement_id' => 'G-TENANT001'],
    ]);
    app(TenantManager::class)->forget();

    $this->withUnencryptedCookie(
        (string) config('consent.shared_cookie'),
        (string) json_encode(['v' => (string) config('consent.version'), 'c' => ['analytics' => true]]),
    )->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertDontSee('G-TENANT001', escape: false);
});
