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
