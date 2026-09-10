<?php

use App\Actions\Tenant\SetTenantFeature;
use App\Enums\Feature;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Is there anything to ask? (SLO-218, docs/19 §11.5)
|--------------------------------------------------------------------------
|
| The banner used to appear wherever a visitor had not answered — including
| inside the embedded demo on the landing page, where it met them on the same
| screen as the marketing site's own bar, in front of the product it was there
| to show. It asked because nobody had answered, not because anything on that
| page depended on the answer.
|
| So the tests below are about the question rather than the answer, and the
| distinction they have to keep alive is this one: a page stops asking because
| it MEASURES NOTHING, never because it is a demo. Both directions are asserted
| — a demo that measures asks, a real tenant that does not measure stays quiet —
| because a rule that happens to produce the right result for demo tenants today
| is indistinguishable, from the outside, from the `is_demo` exemption SLO-209
| deliberately turned down.
|
*/

const SCOPE_GA4 = 'G-SCOPE0001';
const SCOPE_PIXEL = '998877665544332';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    // The platform's own id is absent in dev and CI (config/analytics.php), and
    // several of these tests are about a tenant host, where it is irrelevant
    // anyway. Set explicitly so a future default cannot quietly change what the
    // marketing-site cases are measuring.
    config(['analytics.platform.ga4_measurement_id' => '']);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** @param  array<string, mixed>|null  $analytics */
function scopedTenant(?array $analytics = null, bool $demo = false, string $slug = 'acme'): Tenant
{
    $factory = Tenant::factory()->active();

    if ($demo) {
        $factory = $factory->demo();
    }

    $tenant = $factory->create(['slug' => $slug, 'analytics' => $analytics]);

    app(TenantManager::class)->forget();

    return $tenant;
}

function marketingUrl(string $path = '/'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

/**
 * The scope a real request ends up with, read back off the page.
 *
 * Through HTTP rather than by calling ConsentScope directly: the tenant half of
 * the answer comes from the TenantManager, which only middleware fills in — a
 * hand-built Request would report "no tenant" and quietly pass.
 *
 * @return list<string>
 */
function askableOn(string $url): array
{
    app(TenantManager::class)->forget();

    $askable = [];

    test()->get($url)->assertOk()->assertInertia(function ($page) use (&$askable) {
        /** @var array{props: array{consent: array{askable: list<string>}}} $data */
        $data = $page->toArray();
        $askable = $data['props']['consent']['askable'];
    });

    return $askable;
}

// --- A tenant page asks only about what it actually loads ---

it('asks nothing on the public page of a tenant that measures nothing', function () {
    scopedTenant();

    $this->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('consent.decided', false)
            ->where('consent.askable', []));
});

it('asks about statistics on the public page of a tenant that runs GA4', function () {
    scopedTenant(['ga4_measurement_id' => SCOPE_GA4]);

    $this->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consent.askable', ['analytics']));
});

it('asks about marketing, and only marketing, for a tenant that runs a pixel', function () {
    // The two vendors answer to two categories (docs/19 §11.1.3). A tenant that
    // retargets but does not count must not put a statistics toggle on screen —
    // that toggle would gate nothing, and the visitor's answer to it would be
    // recorded as a decision about something that was never happening.
    scopedTenant(['meta_pixel_id' => SCOPE_PIXEL]);

    $this->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consent.askable', ['marketing']));
});

it('asks about both when the tenant runs both, in the order the app declares', function () {
    scopedTenant(['ga4_measurement_id' => SCOPE_GA4, 'meta_pixel_id' => SCOPE_PIXEL]);

    $this->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consent.askable', ['analytics', 'marketing']));
});

it('stops asking the moment the analytics feature is switched off, ids and all', function () {
    // The ids stay in the column; the feature gate is what decides. If the scope
    // read the settings first, a tenant whose feature was revoked would keep
    // asking for permission to run a tag that can no longer load.
    $tenant = scopedTenant(['ga4_measurement_id' => SCOPE_GA4, 'meta_pixel_id' => SCOPE_PIXEL]);
    app(SetTenantFeature::class)($tenant, Feature::Analytics, false);
    app(TenantManager::class)->forget();

    $this->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consent.askable', []));
});

// --- Not an is_demo exemption: the rule is about measurement, both ways ---

it('asks nothing inside the embedded demo, because the demo measures nothing', function () {
    scopedTenant(demo: true, slug: 'demo-szepsegszalon');

    $this->get(tenantHost('demo-szepsegszalon', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.is_demo', true)
            ->where('consent.askable', []));
});

it('asks on a demo tenant that does measure — being a demo is not the exemption', function () {
    // The test that stops this fix from decaying into `is_demo`. If someone ever
    // "simplifies" ConsentScope by checking the demo flag, this is what goes red.
    scopedTenant(['ga4_measurement_id' => SCOPE_GA4], demo: true, slug: 'demo-szepsegszalon');

    $this->get(tenantHost('demo-szepsegszalon', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.is_demo', true)
            ->where('consent.askable', ['analytics']));
});

// --- The marketing site asks for slot4u's own property, and only in production ---

it('asks about statistics on the marketing site once the platform id is configured', function () {
    config(['analytics.platform.ga4_measurement_id' => 'G-TESTID123']);

    $this->get(marketingUrl('/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consent.askable', ['analytics']));
});

it('asks nothing on the marketing site where no measurement id is set', function () {
    // Every developer laptop and every CI run. The tag is never emitted there
    // (config/analytics.php), so the banner has nothing to gate either.
    $this->get(marketingUrl('/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consent.askable', []));
});

it('never asks on behalf of the platform from a tenant host', function () {
    // §11.1.2: on a tenant subdomain slot4u is the processor, and its own
    // property is not emitted whatever the visitor answers. A configured id must
    // not follow it there and put a question on the tenant's page.
    config(['analytics.platform.ga4_measurement_id' => 'G-TESTID123']);
    scopedTenant();

    $this->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consent.askable', []));
});

// --- The scope never invents a category, and never orphans one ---

it('only ever names categories the app actually asks about', function () {
    config(['analytics.tenant.ga4_category' => 'retired-category']);
    scopedTenant(['ga4_measurement_id' => SCOPE_GA4]);

    $this->get(tenantHost('acme', '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consent.askable', []));
});

it('leaves no configured category without a gate that can raise it', function () {
    // The other direction, and the one a future change is likelier to break: a
    // category added to config/consent.php with nothing consulting it would sit
    // in the settings dialog as a toggle that decides nothing, and a banner
    // could never be raised for it. Every category must be reachable by SOME
    // request — this walks the two that exist.
    config(['analytics.platform.ga4_measurement_id' => 'G-TESTID123']);
    scopedTenant(['ga4_measurement_id' => SCOPE_GA4, 'meta_pixel_id' => SCOPE_PIXEL]);

    $reachable = [
        ...askableOn(marketingUrl('/')),
        ...askableOn(tenantHost('acme', '/')),
    ];

    expect(array_values(array_unique($reachable)))
        ->toEqualCanonicalizing((array) config('consent.categories'));
});
