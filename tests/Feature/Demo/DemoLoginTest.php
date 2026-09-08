<?php

use App\Enums\Role;
use App\Http\Controllers\Tenant\DemoLoginController;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| One-click demo sign-in (SLO-192, docs/21 §2.1)
|--------------------------------------------------------------------------
|
| This is the only route in the application that gives a session to somebody
| who has not authenticated. Everything here is about the fence around it, in
| the order the checks run: the signature, the expiry, the demo flag, and which
| account a visitor lands in.
|
| The demo-flag test is the one that matters most. A signature can be correct
| and the request still be one that must not succeed — that is precisely the
| case where a wrong answer is invisible in review and catastrophic in
| production.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
    Carbon::setTestNow();
});

/** A tenant with a staff account in the given role. */
function demoLoginTenant(bool $isDemo, Role $role = Role::Manager, string $slug = 'demo-proba'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);

    if ($isDemo) {
        $tenant->is_demo = true;
        $tenant->save();
    }

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->getKey()]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $tenant;
}

/** The signed URL the marketing page would hand out. */
function demoLoginUrl(Tenant $tenant, ?int $minutes = null): string
{
    return URL::temporarySignedRoute(
        'tenant.demo.login',
        Carbon::now()->addMinutes($minutes ?? DemoLoginController::LIFETIME_MINUTES),
        ['tenant' => $tenant->slug],
    );
}

it('signs a visitor into a demo tenant with one click', function () {
    $tenant = demoLoginTenant(isDemo: true);

    $this->get(demoLoginUrl($tenant))->assertRedirect('/dashboard');

    $this->assertAuthenticated();
    expect(auth()->user()->tenant_id)->toBe($tenant->getKey());
});

it('⚠️ refuses a tenant that is not a demo, however valid the signature', function () {
    // THE test. The signature is genuine, the URL is fresh, the route is right —
    // and it must still fail, because the tenant holds real data. A 404 rather
    // than a 403: a wrong guess must not confirm that the tenant exists.
    $real = demoLoginTenant(isDemo: false, slug: 'valodi-ceg');

    $this->get(demoLoginUrl($real))->assertNotFound();

    $this->assertGuest();
});

it('refuses a tampered link', function () {
    $tenant = demoLoginTenant(isDemo: true);

    // The signature covers the whole URL, so appending anything breaks it.
    $this->get(demoLoginUrl($tenant).'&role=admin')->assertForbidden();

    $this->assertGuest();
});

it('refuses an unsigned link', function () {
    $tenant = demoLoginTenant(isDemo: true);

    $this->get('http://'.$tenant->slug.'.'.config('tenancy.central_domain').'/demo/login')
        ->assertForbidden();

    $this->assertGuest();
});

it('refuses a link that has expired', function () {
    $tenant = demoLoginTenant(isDemo: true);
    $url = demoLoginUrl($tenant);

    // ⚠️ A link pasted into a chat is dead by the time anyone scrolls back —
    // which is the whole reason the URL is temporary rather than permanent.
    Carbon::setTestNow(Carbon::now()->addMinutes(DemoLoginController::LIFETIME_MINUTES + 1));

    $this->get($url)->assertForbidden();

    $this->assertGuest();
});

it('⚠️ lands the visitor on a Manager, not the owner', function () {
    $tenant = demoLoginTenant(isDemo: true);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $owner = User::factory()->create(['tenant_id' => $tenant->getKey()]);
    $owner->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->get(demoLoginUrl($tenant))->assertRedirect('/dashboard');

    // What a Manager CANNOT reach is half of what the permission matrix
    // demonstrates (docs/03) — a demo signed in as the owner shows a permission
    // model with nothing to show.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    expect(auth()->user()->hasRole(Role::Manager->value))->toBeTrue()
        ->and(auth()->user()->getKey())->not->toBe($owner->getKey());
});

it('falls back to the tenant admin where a persona has no Manager', function () {
    // Not every persona has a second person behind the counter (docs/20 §2) —
    // the practice is one psychologist. The demo still has to open.
    $tenant = demoLoginTenant(isDemo: true, role: Role::TenantAdmin);

    $this->get(demoLoginUrl($tenant))->assertRedirect('/dashboard');

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    expect(auth()->user()->hasRole(Role::TenantAdmin->value))->toBeTrue();
});

it('rotates the session id on the way in', function () {
    $tenant = demoLoginTenant(isDemo: true);

    $this->get('http://'.$tenant->slug.'.'.config('tenancy.central_domain').'/');
    $before = session()->getId();

    $this->get(demoLoginUrl($tenant))->assertRedirect('/dashboard');

    // ⚠️ Session fixation: a visitor arriving with a session id somebody else
    // chose must not keep it across a privilege change. Laravel does this for
    // its own login form; this route has to do it for itself.
    expect(session()->getId())->not->toBe($before);
});

it('⚠️ stops a signed link being replayed a thousand times a minute', function () {
    $tenant = demoLoginTenant(isDemo: true);

    // A signed URL is still a URL. Without a limit, one valid link is a free
    // session generator: the same 15-minute token replayed as fast as a script
    // can send it, each hit costing a login, a session write and a log line.
    // Ten a minute per IP is far more than a human clicking a button.
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->get(demoLoginUrl($tenant))->assertRedirect('/dashboard');
    }

    $this->get(demoLoginUrl($tenant))->assertStatus(429);
});

// --- Embedding: who may put this page in a frame ---------------------------

it('⚠️ lets ONLY a demo tenant be framed, and only by the marketing site', function () {
    config()->set('security.csp.enabled', true);

    $demo = demoLoginTenant(isDemo: true, slug: 'demo-keret');
    $real = demoLoginTenant(isDemo: false, slug: 'valodi-keret');

    $central = config('tenancy.central_domain');

    // A demo tenant: X-Frame-Options is OMITTED (it cannot name one origin —
    // ALLOW-FROM is dead), and frame-ancestors carries the rule instead.
    $demoResponse = $this->get('http://'.$demo->slug.'.'.$central.'/')->assertOk();

    expect($demoResponse->headers->get('X-Frame-Options'))->toBeNull()
        ->and($demoResponse->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'self' http://".$central);

    // ⚠️ Every other tenant keeps DENY and `frame-ancestors 'none'`. A booking
    // page that can be framed is a booking page that can be clickjacked, and
    // this is the assertion that stops the demo exception from leaking.
    $realResponse = $this->get('http://'.$real->slug.'.'.$central.'/')->assertOk();

    expect($realResponse->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($realResponse->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'");
});
