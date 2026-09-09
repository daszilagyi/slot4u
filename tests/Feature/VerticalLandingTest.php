<?php

use App\Enums\Role;
use App\Http\Controllers\VerticalLandingController;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commission\BuildPublicCommissionTerms;
use App\Services\Marketing\DemoPersonaLinks;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\CommissionSettingSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| Vertical landing pages (SLO-198, docs/22 §4)
|--------------------------------------------------------------------------
|
| `/autoszerviz` is the first of five pages that are meant to be one template
| with five blocks of copy. Two properties therefore carry the issue, and both
| are tested here rather than assumed:
|
|   1. the page is a TEMPLATE — a vertical nobody has written code for renders
|      from a config line and a block of Hungarian, and
|   2. the route is not a catch-all — `/{vertical}` sits on the apex domain, one
|      segment wide, and the only thing keeping it from swallowing every future
|      top-level path is the registry it is constrained to.
|
| The second is the one that would be expensive to get wrong: a route that
| matches everything fails silently, by rendering something plausible.
|
*/

afterEach(function () {
    app(TenantManager::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A path on the apex domain.
 *
 * ⚠️ Not named `centralUrl` — Pest lifts a helper declared in a test file into
 * the GLOBAL namespace, and another suite already owns that name. The clash is
 * a fatal error before the first test runs, and it is invisible when this file
 * is run on its own.
 */
function verticalUrl(string $path): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

it('serves the autoszerviz landing on the central domain', function () {
    $this->seed(CommissionSettingSeeder::class);

    $this->get(verticalUrl('/autoszerviz'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Vertical')
            ->where('vertical', 'autoszerviz')
            ->where('demo_tenant', 'demo-autoszerviz')
            ->where('canonical', rtrim((string) config('app.url'), '/').'/autoszerviz')
            ->has('commission')
        );
});

it('⚠️ does not swallow every other top-level path', function () {
    // THE test in this file. `/{vertical}` is one segment on the apex domain: if
    // the registry constraint is ever dropped, this route answers /arak, /blog
    // and everything else somebody adds later — with a landing page for a
    // vertical that does not exist. Nothing throws; the path just quietly stops
    // being available.
    $this->get(verticalUrl('/nincs-ilyen-vertikalis'))->assertNotFound();
    $this->get(verticalUrl('/blog'))->assertNotFound();
});

it('frames the vertical’s own demo tenant, and only that one', function () {
    $this->seed(CommissionSettingSeeder::class);

    Tenant::factory()->active()->create([
        'slug' => 'demo-autoszerviz',
        'name' => 'Csavarkulcs Autószerviz',
        'is_demo' => true,
    ]);

    // A second demo tenant, which the home page would list and this page must
    // not: the visitor arrived from a workshop ad and did not come to browse.
    Tenant::factory()->active()->create([
        'slug' => 'demo-fitnesz',
        'name' => 'Premium Fitness Studio',
        'is_demo' => true,
    ]);

    $this->get(verticalUrl('/autoszerviz'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Vertical')
            ->has('demo_personas', 1)
            ->where('demo_personas.0.slug', 'demo-autoszerviz')
        );
});

it('renders without a demo section when that tenant is not seeded', function () {
    // The honest failure mode, and the same one the home page has: no demo
    // tenant means no live frame, rather than a frame pointing at a 404. It is
    // the state a fresh production install is in until `demo:seed` has run.
    $this->seed(CommissionSettingSeeder::class);

    $this->get(verticalUrl('/autoszerviz'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('demo_personas', 0));
});

it('⚠️ is a template: a vertical nobody wrote code for renders from copy alone', function () {
    // The acceptance criterion that decides whether the other four pages are a
    // week of work or an afternoon. Nothing below touches a controller, a
    // component or a route file — a registry entry and a block of Hungarian,
    // which is exactly what adding `/szepsegszalon` will be.
    config(['verticals.probavertikalis' => ['demo_tenant' => null]]);

    Lang::addLines([
        'app.verticals.probavertikalis' => verticalCopyFixture(),
    ], 'hu');

    // The route is built from the registry at boot, so a vertical registered
    // mid-test needs its route registered too. That is the ONE line a new
    // vertical does not have to write — routes/web.php already generates it from
    // the same config key.
    Route::domain(config('tenancy.central_domain'))
        ->get('/{vertical}', VerticalLandingController::class)
        ->whereIn('vertical', ['probavertikalis']);

    $this->get(verticalUrl('/probavertikalis'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Vertical')
            ->where('vertical', 'probavertikalis')
        );
});

it('⚠️ refuses a registered vertical that has no copy', function () {
    // Half a vertical is not a page. Without this the visitor would get a screen
    // of dotted translation keys, which is worse than a 404 in every way that
    // matters — including that a 404 is the thing a crawler understands.
    config(['verticals.copytalan' => ['demo_tenant' => null]]);

    $call = fn () => app()->call(
        [app(VerticalLandingController::class), '__invoke'],
        [
            'vertical' => 'copytalan',
            'terms' => app(BuildPublicCommissionTerms::class),
            'demos' => app(DemoPersonaLinks::class),
        ],
    );

    expect($call)->toThrow(NotFoundHttpException::class);
});

it('quotes the commission figures that are actually configured', function () {
    // Same guarantee the home page has (WelcomeTest): the price on a landing
    // page comes from the settings the platform bills on, never from the copy.
    // ⚠️ docs/22 §4 asks for a highlighted "Közepes csomag" here — there are no
    // packages any more (CLAUDE.md, docs/10), so there is nothing to highlight
    // and this is what the page quotes instead.
    $this->seed(CommissionSettingSeeder::class);

    $this->get(verticalUrl('/autoszerviz'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('commission.rate_bps')
            ->has('commission.free_threshold_minor')
        );
});

it('states the model without figures when nothing is configured', function () {
    $this->get(verticalUrl('/autoszerviz'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('commission', null));
});

it('signs the demo link so the one-click admin view works from here too', function () {
    $this->seed(CommissionSettingSeeder::class);
    // The demo sign-in lands on a real account, so the roles have to exist and
    // somebody has to hold one — without that the route 404s by design.
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    $tenant = Tenant::factory()->active()->create([
        'slug' => 'demo-autoszerviz',
        'name' => 'Csavarkulcs Autószerviz',
        'is_demo' => true,
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    User::factory()->create(['tenant_id' => $tenant->getKey()])->assignRole(Role::Manager->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $adminUrl = $this->get(verticalUrl('/autoszerviz'))
        ->assertOk()
        ->viewData('page')['props']['demo_personas'][0]['admin_url'];

    // Two clicks from the hero to a running dashboard is the issue's acceptance
    // criterion; the link the page hands out has to actually work.
    $this->get($adminUrl)->assertRedirect('/dashboard');
});

it('⚠️ still refuses to sign a visitor into a tenant that is not a demo', function () {
    // Inherited from SLO-192 and re-checked from this entry point: the fence is
    // on the login route, not on the page that links to it, so a new page cannot
    // widen it — and this test is what says so out loud.
    $this->seed(CommissionSettingSeeder::class);

    $real = Tenant::factory()->active()->create(['slug' => 'valodi-szerviz']);

    expect($real->refresh()->is_demo)->toBeFalse();

    $this->get(verticalUrl('/autoszerviz'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('demo_personas', 0));
});

it('carries the SEO head the vertical exists for', function () {
    // Title, description and canonical are the whole reason this is a page
    // rather than a section: a vertical can only rank for its own phrase.
    $this->seed(CommissionSettingSeeder::class);

    $response = $this->get(verticalUrl('/autoszerviz'))->assertOk();

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and(trans('app.verticals.autoszerviz.meta_title'))
        ->toContain('Autószerviz');
});

/**
 * A complete, minimal content block — every key `Vertical.tsx` reads.
 *
 * @return array<string, mixed>
 */
function verticalCopyFixture(): array
{
    $item = ['icon' => 'bell', 'title' => 'Cím', 'body' => 'Szöveg'];

    return [
        'meta_title' => 'Próba vertikális',
        'meta_description' => 'Próba leírás',
        'eyebrow' => 'Próbaszakmának',
        'title_lead' => 'Egy',
        'title_accent' => 'próba',
        'title_tail' => 'címsor.',
        'lead' => 'Bevezető.',
        'cta_primary' => 'Kipróbálom',
        'cta_secondary' => 'Demó',
        'cta_caption' => 'Nincs bankkártya.',
        'widget' => ['title' => 'Szolgáltatás', 'day' => 'Hétfő'],
        'pains' => ['title' => 'Fájdalmak', 'items' => [$item]],
        'steps' => ['title' => 'Lépések', 'lead' => 'Bevezető.', 'items' => [['title' => 'Egy', 'body' => 'Szöveg']]],
        'features' => ['title' => 'Funkciók', 'items' => [$item]],
        'demo' => ['title' => 'Demó', 'lead' => 'Bevezető.', 'caption' => 'Fiktív adatok'],
        'not_for' => ['title' => 'Nem csinál', 'lead' => 'Bevezető.', 'items' => ['Semmit.'], 'footnote' => 'Lábjegyzet.'],
        'pricing' => ['title' => 'Ár', 'lead' => 'Bevezető.'],
        'faq' => ['title' => 'GYIK', 'items' => [['q' => 'Kérdés?', 'a' => 'Válasz.']]],
        'closing' => ['title' => 'Zárás', 'lead' => 'Bevezető.'],
    ];
}
