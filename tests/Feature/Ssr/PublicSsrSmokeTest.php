<?php

use App\Enums\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\CommissionSettingSeeder;
use Illuminate\Support\Facades\Vite;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    app(TenantManager::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * Whether an Inertia SSR renderer is listening at the configured URL. The smoke
 * test needs a live node process (the `ssr` docker service, or
 * `node bootstrap/ssr/ssr.js`); without one it skips rather than failing, so the
 * normal suite stays green everywhere while CI runs it against a started server.
 */
function ssrRendererReachable(): bool
{
    $parts = parse_url((string) config('inertia.ssr.url'));
    $host = $parts['host'] ?? '127.0.0.1';
    $port = $parts['port'] ?? 13714;

    $conn = @fsockopen($host, $port, $errno, $errstr, 1);

    if ($conn === false) {
        return false;
    }

    fclose($conn);

    return true;
}

beforeEach(function () {
    // In hot (vite dev) mode Inertia routes SSR through the vite dev server, not
    // the production bundle server this test targets — skip so we only assert the
    // node-bundle path (docs/01 prod SSR), which is what CI exercises.
    if (Vite::isRunningHot()) {
        $this->markTestSkipped('Vite dev server is hot — production SSR bundle path is not in play.');
    }

    if (! ssrRendererReachable()) {
        $this->markTestSkipped(
            'No Inertia SSR renderer reachable at '.config('inertia.ssr.url')
            .' — start the `ssr` service (or run `node bootstrap/ssr/ssr.js`).'
        );
    }

    // Turn SSR on for this test only (the suite runs with it off) and fail loud
    // if the render errors, so a broken public page can never pass silently.
    config()->set('inertia.ssr.enabled', true);
    config()->set('inertia.ssr.throw_on_error', true);
});

/**
 * The response HTML minus every place Inertia serializes its props (the
 * `<script data-page ...>JSON</script>` block and any `data-page="..."`
 * attribute). What remains is the server-rendered markup only — so an assertion
 * against it cannot be satisfied by the serialized prop alone, only by real SSR.
 */
function renderedMarkupOnly(string $content): string
{
    $stripped = preg_replace('#<script[^>]*data-page="app"[^>]*>.*?</script>#s', '', $content);

    return (string) preg_replace('/ data-page="[^"]*"/', '', (string) $stripped);
}

it('server-renders the public home with the service list in the HTML', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);
    $category = ServiceCategory::factory()->forTenant($tenant)->create(['name' => 'Masszázs']);
    Service::factory()->forTenant($tenant)->create([
        'category_id' => $category->id,
        'name' => 'Svédmasszázs SSR',
        'active' => true,
    ]);

    $content = $this->get(tenantHost('acme'))->assertOk()->getContent();
    $rendered = renderedMarkupOnly($content);

    // The service name survives prop-stripping → it was rendered into the DOM.
    expect($rendered)
        ->toContain('Svédmasszázs SSR')
        ->toContain('id="app"');
});

it('leaves the public home root non-empty (SSR actually ran, not a client shell)', function () {
    Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);

    $content = $this->get(tenantHost('acme'))->assertOk()->getContent();
    $rendered = renderedMarkupOnly($content);

    // A client-only shell has no rendered translations in the markup (they live
    // only in the stripped props); a server-rendered page carries the heading.
    expect($rendered)->toContain('Kapcsolat'); // tenant.home.contact_title, rendered
});

it('server-renders the marketing hero, headline and widget included', function () {
    // ⚠️ The hero is the LCP element (SLO-203): the H1 has to arrive WITH the
    // document, not after React boots, or the largest paint waits on JavaScript
    // and the < 2s mobile budget is gone before a byte of it is measured. It is
    // also the SEO surface — a headline that only exists after hydration is a
    // headline a crawler may never see.
    //
    // Asserted against prop-stripped markup, so a serialized translation string
    // cannot pass this on its own.
    $this->seed(CommissionSettingSeeder::class);

    $content = $this->get('http://'.config('tenancy.central_domain'))->assertOk()->getContent();
    $rendered = renderedMarkupOnly($content);

    expect($rendered)
        // Both halves of the headline — the accent span is a separate node, and
        // splitting it wrongly is exactly the kind of slip that renders as one
        // run-on sentence nobody notices in a diff.
        ->toContain('Online foglalás, ami nem kerül semmibe,')
        ->toContain('amíg nincs miből fizetned')
        // The caption that answers the objection next to the button.
        ->toContain('Nem kérünk bankkártyát')
        // The slot widget is a component, not a picture (docs/21 §2) — which is
        // only worth anything if it actually renders on the server too.
        ->toContain('Szabad időpontok')
        ->toContain('11:15')
        // Header nav and the footer's own copy: the shell around the hero.
        ->toContain('Funkciók')
        ->toContain('Magyar fejlesztés');
});

it('server-renders the middle of the landing page too', function () {
    // The sections below the fold are the ones a crawler reads and a visitor
    // scrolls to (SLO-204). They animate on scroll, which is exactly the shape
    // of change that can leave markup empty until JavaScript runs — so they are
    // asserted against prop-stripped HTML like everything else here.
    $this->seed(CommissionSettingSeeder::class);

    $content = $this->get('http://'.config('tenancy.central_domain'))->assertOk()->getContent();
    $rendered = renderedMarkupOnly($content);

    expect($rendered)
        // The assurance strip — and ⚠️ specifically NOT a customer count or a
        // tenant logo. Every claim here is one that holds with zero customers.
        ->toContain('Adataid az EU-ban')
        ->toContain('Nincs havidíj')
        // Three steps, and the product showcase behind them.
        ->toContain('Három lépés')
        ->toContain('Kiteszed a linked')
        ->toContain('A naptár, ami helyetted figyel')
        // Two of the six feature blocks, including one of the pair added to
        // reach the 2×3 grid — both are shipped features, not promises.
        ->toContain('Ütközésmentes naptár')
        ->toContain('Online fizetés');
});

it('server-renders the demo section without loading the demo itself', function () {
    // ⚠️ Two claims in one test, and the second is the load-bearing one.
    //
    // The cards have to arrive with the document — they are the section's
    // content, and a crawler reading "try it live" over an empty column learns
    // nothing. The IFRAME must NOT: it is a whole application, gated behind an
    // IntersectionObserver precisely so it never competes with the hero's LCP
    // budget (docs/21 §2.1). If it ever shows up in server markup, that gate has
    // been lost — and the symptom is a slow page, which nobody attributes to a
    // diff in this file.
    $this->seed(CommissionSettingSeeder::class);

    Tenant::factory()->active()->create([
        'slug' => 'demo-fitnesz',
        'name' => 'Premium Fitness Studio SSR',
        'is_demo' => true,
    ]);

    $content = $this->get('http://'.config('tenancy.central_domain'))->assertOk()->getContent();
    $rendered = renderedMarkupOnly($content);

    expect($rendered)
        ->toContain('Próbáld ki élőben')
        ->toContain('Premium Fitness Studio SSR')
        // The persona's size label, off the lang file rather than the database.
        ->toContain('Teljes')
        // ⚠️ The warning, which is the one line on this page that must never be
        // quietly dropped: the personas are written to look like real
        // businesses.
        ->toContain('Fiktív adatok')
        // The host in the frame's address bar — the visitor's own subdomain,
        // shown as it will look.
        ->toContain('demo-fitnesz.'.config('tenancy.central_domain'))
        ->not->toContain('<iframe');
});

it('server-renders the closing block, FAQ answers included', function () {
    // ⚠️ The answers, not just the questions. An accordion whose text lives only
    // in React state is one a crawler never reads — and the FAQ is the part of
    // this page with real search value. `<details>` renders its content either
    // way, which is why it is a `<details>` and not a div with an onClick.
    $this->seed(CommissionSettingSeeder::class);

    $content = $this->get('http://'.config('tenancy.central_domain'))->assertOk()->getContent();
    $rendered = renderedMarkupOnly($content);

    expect($rendered)
        ->toContain('Amit a legtöbben megkérdeznek')
        ->toContain('Mennyibe kerül valójában?')
        // ⚠️ The uncomfortable answer specifically: a no-show still counts
        // towards turnover (docs/10 §3). A surprise line on the first invoice
        // costs more trust than this sentence does.
        ->toContain('no-show és a 24 órán belüli lemondás viszont beleszámít')
        ->toContain('A regisztráció ingyenes');

    // The structured data ships with the answers, so a search result can show
    // them without a click.
    expect($content)
        ->toContain('"@type":"FAQPage"')
        ->toContain('"@type":"Question"');
});

it('⚠️ leaves out the testimonials rather than inventing them', function () {
    // Row 7 of docs/21 §2 is deliberately absent (Daniel's call): without real,
    // quotable, permitted customers it could only hold invented ones. This is
    // the guard against somebody adding a "placeholder" review — the kind
    // nobody remembers to remove.
    $this->seed(CommissionSettingSeeder::class);

    $content = $this->get('http://'.config('tenancy.central_domain'))->assertOk()->getContent();

    // The section's own heading key is not defined at all, which is the real
    // guard; this asserts the shape it would take if somebody reintroduced it.
    expect($content)
        ->not->toContain('Vélemények')
        ->not->toContain('ellenőrzött vélemény');
});

it('⚠️ never claims customers it does not have', function () {
    // The guard for a decision, not a bug (docs/21, the box under §2's table):
    // the trust strip and the testimonials were both cut because inventing a
    // customer count — or borrowing the demo tenants' names as if they were
    // references — costs exactly the trust those sections exist to build.
    //
    // This fails the moment somebody reinstates the logo wall.
    $this->seed(CommissionSettingSeeder::class);

    $content = $this->get('http://'.config('tenancy.central_domain'))->assertOk()->getContent();

    foreach (['GlamZone', 'Premium Fitness', 'Lélekút', 'Fényliget', 'Csavarkulcs'] as $fixture) {
        expect($content)->not->toContain($fixture);
    }

    // No SLA figure either: docs/17 is monitoring, not a contractual promise.
    expect($content)->not->toContain('99,9%')->not->toContain('99.9%');
});

it('server-renders an authenticated admin page without breaking (global SSR)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);

    // The admin panel is staff-only (SLO-86), so a role-less user would 403 here.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);

    // Enabling SSR globally must not break the (auth-gated) admin area — with
    // throw_on_error on, a browser-API-in-render bug would fail loudly here.
    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
});

it('server-renders the admin calendar, grid and all', function () {
    // The calendar is the most interactive admin page (drag-and-drop, card action
    // menus, the quick-booking sheet — SLO-44, SLO-136), so it is where a
    // browser-API-in-render slip is most likely; with throw_on_error on, that fails
    // here instead of blanking the page in production.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);

    $response = $this->actingAs($user)
        ->get(tenantHost('acme', '/calendar'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->component('Admin/Calendar/Index'));

    // Real server-rendered markup, not just the serialized props.
    expect(renderedMarkupOnly($response->getContent()))->toContain('Naptár');
});

it('server-renders the marketing landing with its price in the HTML (SLO-50)', function () {
    // The landing is the one page written for search engines and link previews,
    // so a client-only shell there is not a cosmetic problem — it is the page
    // being invisible. The price is asserted after prop-stripping because it is
    // computed server-side: if it survives, SSR ran AND the figure reached the
    // markup rather than only the serialized props.
    $this->seed(CommissionSettingSeeder::class);

    $content = $this->get('http://'.config('tenancy.central_domain').'/')
        ->assertOk()
        ->getContent();

    $rendered = renderedMarkupOnly($content);

    expect($rendered)
        ->toContain('id="app"')
        // welcome.pricing_title, rendered by the server.
        ->toContain('fizetsz, ha keresel')
        // The free threshold, formatted from commission_settings (10 000 Ft —
        // Intl uses a non-breaking space as the group separator).
        ->toContain('10'."\u{A0}".'000');
});
