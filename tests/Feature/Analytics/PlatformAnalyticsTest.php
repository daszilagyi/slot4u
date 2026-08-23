<?php

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| slot4u's own GA4 tag (SLO-172)
|--------------------------------------------------------------------------
|
| Three independent conditions have to hold before Google learns anything: an
| id is configured, the host is the marketing site, and the visitor said yes.
| Each of the tests below removes exactly one of them, because the failure that
| matters here is silent — a tag that loads when it should not looks identical
| to one that loads when it should, from everywhere except the visitor's
| network tab.
|
| Each test makes ONE request on purpose. The decision object is scoped to the
| container, and a test app is not torn down between calls the way a PHP-FPM
| request is, so a second request in the same test would reuse the first
| request's answer and quietly prove nothing.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    config(['analytics.platform.ga4_measurement_id' => 'G-TESTID123']);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function centralHost(string $path = '/'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

/** @param  array<string, bool>  $categories */
function grantedConsent(array $categories): string
{
    return (string) json_encode([
        'v' => (string) config('consent.version'),
        'c' => $categories,
    ]);
}

// --- When the tag is emitted ---

it('loads the GA4 tag on the marketing site for a visitor who granted analytics', function () {
    $response = $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        grantedConsent(['analytics' => true, 'marketing' => false]),
    )->get(centralHost('/'))->assertOk();

    $response->assertSee('googletagmanager.com/gtag/js?id=G-TESTID123', escape: false);
    // Reporting page views by hand is the whole reason an SPA can count more
    // than one — if this flips back to gtag's automatic view, every visit after
    // the landing page stops being measured and nothing else breaks.
    $response->assertSee('send_page_view', escape: false);
});

it('names Google in the CSP on exactly the request that loaded the tag', function () {
    $response = $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        grantedConsent(['analytics' => true, 'marketing' => false]),
    )->get(centralHost('/'))->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain('https://www.googletagmanager.com')
        ->and($csp)->toContain('https://*.google-analytics.com');
});

// --- When it is not ---

it('emits nothing for a visitor who has not decided yet', function () {
    // Silence is not consent. The banner is still up at this point, and a tag
    // that loaded behind it would make the question decorative.
    $response = $this->get(centralHost('/'))->assertOk();

    $response->assertDontSee('googletagmanager.com', escape: false);
    expect((string) $response->headers->get('Content-Security-Policy'))
        ->not->toContain('googletagmanager');
});

it('emits nothing for a visitor who declined analytics', function () {
    $response = $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        grantedConsent(['analytics' => false, 'marketing' => true]),
    )->get(centralHost('/'))->assertOk();

    // Marketing granted, analytics refused: the categories are separate
    // questions, and GA4 hangs off the one that was answered no.
    $response->assertDontSee('googletagmanager.com', escape: false);
});

it('never loads the platform tag on a tenant subdomain, consent or not', function () {
    // On a tenant host the tenant is the controller and slot4u the processor
    // (docs/19 §2). A slot4u-owned property collecting that traffic would be the
    // platform taking data it only holds on someone else's behalf.
    Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $response = $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        grantedConsent(['analytics' => true, 'marketing' => true]),
    )->get(tenantHost('acme', '/'))->assertOk();

    $response->assertDontSee('googletagmanager.com', escape: false);
    expect((string) $response->headers->get('Content-Security-Policy'))
        ->not->toContain('googletagmanager');
});

it('emits nothing when no measurement id is configured', function () {
    // The state of dev, CI, and any host whose .env was never given the id.
    config(['analytics.platform.ga4_measurement_id' => '']);

    $response = $this->withUnencryptedCookie(
        (string) config('consent.cookie'),
        grantedConsent(['analytics' => true, 'marketing' => true]),
    )->get(centralHost('/'))->assertOk();

    $response->assertDontSee('googletagmanager.com', escape: false);
});
