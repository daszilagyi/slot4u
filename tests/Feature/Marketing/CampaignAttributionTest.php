<?php

use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Services\Marketing\CampaignAttribution;
use App\Settings\TenantSignupSource;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Which campaign produced this tenant (SLO-210, docs/22 §4)
|--------------------------------------------------------------------------
|
| GA4 and Meta can say how many clicks a campaign bought. Only we can say which
| of those became a company, and comparing that between vertical landings is the
| entire business case for building them (docs/22 §7.1).
|
| Two things these tests exist to hold in place, neither of which would announce
| itself if it broke:
|
| 1. The attribution lives in the SESSION, not in a cookie of its own. An
|    attribution cookie needs consent, and consent rates vary by audience —
|    which would bias the measurement in exactly the dimension it compares.
| 2. It is captured ONLY on slot4u's own marketing pages. `/register` is a
|    domain-less Fortify route, so `utm_*` can arrive on a tenant's host too,
|    where the campaign is the TENANT's, bought with the tenant's money. Taking
|    it would be the same controller-boundary error as SLO-220.
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

function campaignMarketingUrl(string $path = '/'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

/**
 * The two platform documents a sign-up has to accept, created once.
 *
 * Reused rather than re-created per call: publishing a second version of the
 * same type mid-test would put a NEWER document in force than the one the form
 * is about to submit, which is the very race SLO-161 catches — a real rule,
 * firing on a fixture rather than on the thing under test.
 *
 * @return list<int>
 */
function platformDocumentIds(): array
{
    $documents = LegalDocument::query()->get();

    if ($documents->count() < 2) {
        $documents = collect([
            LegalDocument::factory()->platform()->terms()->create(),
            LegalDocument::factory()->platform()->privacy()->create(),
        ]);
    }

    return $documents->map->getKey()->values()->all();
}

/**
 * @param  array<string, mixed>  $extra
 */
function registerCompany(array $extra = [], string $slug = 'teszt-kft'): void
{
    // ⚠️ assertSessionHasNoErrors, not only assertRedirect: a rejected sign-up
    // also redirects (back, with errors), so asserting the redirect alone would
    // let a broken fixture pass as a successful registration and fail later on a
    // confusing "no query results".
    test()->post(campaignMarketingUrl('/register'), [
        'company_name' => 'Teszt Kft.',
        'slug' => $slug,
        'name' => 'Cég Admin',
        'email' => $slug.'@teszt.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'accepted_legal' => true,
        'legal_document_ids' => platformDocumentIds(),
        ...$extra,
    ])->assertSessionHasNoErrors()->assertRedirect();
}

function attribution(): CampaignAttribution
{
    return app(CampaignAttribution::class);
}

// --- Capture, and where it is allowed to happen ---

it('remembers the campaign a visitor arrived on', function () {
    $this->get(campaignMarketingUrl('/?utm_source=meta&utm_medium=cpc&utm_campaign=szerviz-tavasz'))
        ->assertOk();

    $source = attribution()->current();

    expect($source->utmSource)->toBe('meta')
        ->and($source->utmMedium)->toBe('cpc')
        ->and($source->utmCampaign)->toBe('szerviz-tavasz')
        ->and($source->landingPath)->toBe('/');
});

it('remembers which vertical landing they arrived on, campaign or not', function () {
    // Half of what docs/22 §7.1 compares is the PAGE, not the ad: someone who
    // reaches /autoszerviz through search is still evidence about that vertical.
    $this->get(campaignMarketingUrl('/autoszerviz'))->assertOk();

    expect(attribution()->current()->landingPath)->toBe('/autoszerviz')
        ->and(attribution()->current()->hasCampaign())->toBeFalse();
});

it('⚠️ takes nothing from a tenant own host, where the campaign is the tenant own', function () {
    // The controller boundary, and the reason this middleware is gated on a
    // route name rather than on the presence of `utm_*`. A tenant advertising
    // its own booking page must not have that spend quietly recorded as ours.
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/?utm_source=tenant-ad&utm_campaign=acme-nyar'))
        ->assertOk();

    expect(attribution()->current()->isEmpty())->toBeTrue();
});

it('takes nothing from a page that is not the marketing surface', function () {
    $this->get(campaignMarketingUrl('/register?utm_source=meta'))->assertOk();

    expect(attribution()->current()->isEmpty())->toBeTrue();
});

// --- Which touch wins ---

it('keeps the first campaign when the visitor comes back in the same visit', function () {
    $this->get(campaignMarketingUrl('/?utm_source=meta&utm_campaign=first'))->assertOk();
    $this->get(campaignMarketingUrl('/?utm_source=google&utm_campaign=second'))->assertOk();

    expect(attribution()->current()->utmCampaign)->toBe('first');
});

it('⚠️ lets a paid click displace a source-less arrival', function () {
    // The one overwrite that is allowed, and the error it prevents is the
    // expensive one: read the page organically, then click the ad, and
    // first-touch alone would file the visit as free traffic we had just paid
    // for — making the comparison argue for the wrong answer.
    $this->get(campaignMarketingUrl('/'))->assertOk();
    $this->get(campaignMarketingUrl('/autoszerviz?utm_source=meta&utm_campaign=szerviz'))->assertOk();

    $source = attribution()->current();

    expect($source->utmSource)->toBe('meta')
        ->and($source->landingPath)->toBe('/autoszerviz');
});

// --- Untrusted input ---

it('truncates a campaign name long enough to fill the column', function () {
    $this->get(campaignMarketingUrl('/?utm_campaign='.str_repeat('a', 5000)))->assertOk();

    expect(mb_strlen((string) attribution()->current()->utmCampaign))
        ->toBe(TenantSignupSource::MAX_LENGTH);
});

it('strips control characters rather than storing them', function () {
    $this->get(campaignMarketingUrl('/?utm_source=me%00ta%0A'))->assertOk();

    expect(attribution()->current()->utmSource)->toBe('meta');
});

// --- What lands on the tenant ---

it('writes the campaign onto the tenant that signs up', function () {
    $this->get(campaignMarketingUrl('/autoszerviz?utm_source=meta&utm_medium=cpc&utm_campaign=szerviz-tavasz'))
        ->assertOk();

    registerCompany();

    $tenant = Tenant::query()->where('slug', 'teszt-kft')->sole();

    expect($tenant->signup_utm_source)->toBe('meta')
        ->and($tenant->signup_utm_medium)->toBe('cpc')
        ->and($tenant->signup_utm_campaign)->toBe('szerviz-tavasz')
        ->and($tenant->signup_landing_path)->toBe('/autoszerviz')
        ->and($tenant->signup_landed_at)->not->toBeNull();
});

it('leaves the columns null when nothing is known, rather than inventing a direct source', function () {
    registerCompany();

    $tenant = Tenant::query()->where('slug', 'teszt-kft')->sole();

    expect($tenant->signup_utm_source)->toBeNull()
        ->and($tenant->signup_landing_path)->toBeNull()
        ->and($tenant->signup_landed_at)->toBeNull();
});

it('⚠️ refuses to take the attribution from the sign-up form itself', function () {
    // These columns are not fillable on purpose: the values start life in a
    // query string, and the one number the marketing spend is judged on must not
    // be writable by whoever is being measured.
    registerCompany(['signup_utm_source' => 'self-declared', 'signup_utm_campaign' => 'made-up']);

    $tenant = Tenant::query()->where('slug', 'teszt-kft')->sole();

    expect($tenant->signup_utm_source)->toBeNull()
        ->and($tenant->signup_utm_campaign)->toBeNull();
});

it('hands the attribution over once and stops holding it', function () {
    // ⚠️ This test exists because a mutation found the hole: swapping `take()`
    // for `current()` in the sign-up path broke nothing. The end-to-end route
    // cannot tell the difference — signing out flushes the session anyway — so
    // the consume-once rule has to be pinned where it is actually made.
    //
    // It is not paranoia. Once the campaign is on the tenant row, the session
    // copy is a second, staler answer to the same question, and we have no
    // reason left to hold it.
    $this->get(campaignMarketingUrl('/?utm_source=meta&utm_campaign=egyszer'))->assertOk();

    expect(attribution()->take()->utmCampaign)->toBe('egyszer')
        ->and(attribution()->current()->isEmpty())->toBeTrue();
});

it('stops holding the campaign once it is on the tenant row', function () {
    // The call-site half of the rule above. Pinning `take()` alone was not
    // enough: the sign-up path could still call `current()` and keep a copy of a
    // marketing identifier in the session of someone who is now a customer, and
    // every test would stay green.
    $this->get(campaignMarketingUrl('/?utm_source=meta&utm_campaign=egyszer'))->assertOk();

    registerCompany();

    expect(Tenant::query()->where('slug', 'teszt-kft')->sole()->signup_utm_campaign)->toBe('egyszer')
        ->and(attribution()->current()->isEmpty())->toBeTrue();
});

it('does not carry a campaign across a sign-out into the next company', function () {
    // One browser, two companies: sign up, sign out, sign up again. The campaign
    // brought the first one. Letting it stand for the second would credit a
    // campaign with a tenant it never touched, and the number that decides where
    // the ad budget goes would drift upward on its own.
    $this->get(campaignMarketingUrl('/?utm_source=meta&utm_campaign=egyszer'))->assertOk();

    registerCompany(slug: 'elso-kft');
    $this->post(campaignMarketingUrl('/logout'))->assertRedirect();

    registerCompany(slug: 'masodik-kft');

    expect(Tenant::query()->where('slug', 'elso-kft')->sole()->signup_utm_campaign)->toBe('egyszer')
        ->and(Tenant::query()->where('slug', 'masodik-kft')->sole()->signup_utm_campaign)->toBeNull();
});

// --- What the superadmin can actually read off it ---

/** Attribution is not mass-assignable, so a fixture has to state that it means it. */
function tenantFromCampaign(string $slug, ?string $source, bool $demo = false): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug, 'is_demo' => $demo]);

    // A null source means nothing is known at all, landing page included — the
    // state most existing tenants are in. Leaving a path behind would make them
    // look attributed to a vertical they were never seen on.
    $tenant->forceFill($source === null ? [] : [
        'signup_utm_source' => $source,
        'signup_utm_campaign' => $source.'-kampany',
        'signup_landing_path' => '/autoszerviz',
        'signup_landed_at' => now(),
    ])->save();

    return $tenant;
}

it('shows the acquisition breakdown on the tenant list, biggest source first', function () {
    tenantFromCampaign('a-kft', 'meta');
    tenantFromCampaign('b-kft', 'meta');
    tenantFromCampaign('c-kft', 'google');
    tenantFromCampaign('d-kft', null);

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sources.0.source', 'meta')
            ->where('sources.0.total', 2)
            ->where('sources.1.source', 'google')
            ->where('sources.1.total', 1));
});

it('⚠️ keeps demo tenants out of the breakdown', function () {
    // They are seeded and rebuilt nightly (SLO-191), so they have no source at
    // all. Counted, they would inflate the unknown bucket — which is the
    // denominator anyone reading this divides by.
    tenantFromCampaign('valodi-kft', null);
    tenantFromCampaign('demo-szalon', null, demo: true);

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sources.0.source', null)
            ->where('sources.0.total', 1));
});

it('counts an archived tenant in the breakdown, because the campaign still produced it', function () {
    tenantFromCampaign('elment-kft', 'meta')->delete();

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sources.0.total', 1));
});

it('narrows the list to one source when asked', function () {
    tenantFromCampaign('meta-kft', 'meta');
    tenantFromCampaign('google-kft', 'google');

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants?source=meta'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.source', 'meta')
            ->has('tenants.data', 1)
            ->where('tenants.data.0.slug', 'meta-kft'));
});

it('carries the whole source onto the tenant detail page', function () {
    $tenant = tenantFromCampaign('reszletes-kft', 'meta');

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants/'.$tenant->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.signup.utm_source', 'meta')
            ->where('tenant.signup.utm_campaign', 'meta-kampany')
            ->where('tenant.signup.landing_path', '/autoszerviz')
            ->whereNot('tenant.signup.landed_at', null));
});

it('says it does not know, rather than showing a source, for a tenant without one', function () {
    $tenant = tenantFromCampaign('ismeretlen-kft', null);

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants/'.$tenant->getKey()))
        ->assertOk()
        // Not an empty object: the detail page renders a sentence explaining the
        // absence, and it can only tell the difference from null.
        ->assertInertia(fn ($page) => $page->where('tenant.signup', null));
});
