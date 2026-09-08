<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The public demo entry point (SLO-192, docs/21 §2.1)
|--------------------------------------------------------------------------
|
| The one-click sign-in itself is fenced in DemoLoginTest. This file covers what
| surrounds it: which tenants the landing page offers, that the links it hands
| out actually work, and that a visitor inside a demo is told it is one.
|
| The list is the part worth guarding. It is built from `is_demo` rather than a
| hard-coded array precisely so it cannot advertise a persona that has been
| removed — and the same property is what would quietly publish a REAL tenant's
| booking page, complete with a sign-in link, if the filter ever slipped.
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

/** A tenant with a Manager, so the demo sign-in has somewhere to land. */
function entryPointTenant(string $slug, bool $isDemo, TenantStatus $status = TenantStatus::Active): Tenant
{
    $tenant = Tenant::factory()->create([
        'slug' => $slug,
        'name' => ucfirst($slug),
        'status' => $status,
        'is_demo' => $isDemo,
        'settings' => ['description' => 'Egy kitalált vállalkozás. Több mondat követi, amiből csak az első kell.'],
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    User::factory()->create(['tenant_id' => $tenant->getKey()])->assignRole(Role::Manager->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $tenant;
}

// --- Which tenants the landing offers ---------------------------------------

it('offers the seeded demo tenants, newest last', function () {
    entryPointTenant('demo-pszichologus', isDemo: true);
    entryPointTenant('demo-fitnesz', isDemo: true);

    $this->get('http://'.config('tenancy.central_domain'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('demo_personas', 2)
            ->where('demo_personas.0.slug', 'demo-pszichologus')
            ->where('demo_personas.1.slug', 'demo-fitnesz')
            // One sentence off the tenant's own profile, not a second copy of
            // the description written into the landing page.
            ->where('demo_personas.0.description', 'Egy kitalált vállalkozás.')
        );
});

it('keeps a one-sentence profile readable', function () {
    // No ". " to cut at, so the whole (already punctuated) string comes back —
    // and it must not come back with two full stops.
    Tenant::factory()->create([
        'slug' => 'demo-egy',
        'name' => 'Egy',
        'status' => TenantStatus::Active,
        'is_demo' => true,
        'settings' => ['description' => 'Egyetlen mondat, ponttal a végén.'],
    ]);

    $this->get('http://'.config('tenancy.central_domain'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('demo_personas.0.description', 'Egyetlen mondat, ponttal a végén.')
        );
});

it('offers a tenant with no profile text at all', function () {
    Tenant::factory()->create([
        'slug' => 'demo-ures',
        'name' => 'Üres',
        'status' => TenantStatus::Active,
        'is_demo' => true,
        'settings' => [],
    ]);

    $this->get('http://'.config('tenancy.central_domain'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('demo_personas.0.description', null)
        );
});

it('⚠️ never offers a tenant that holds real data', function () {
    // THE test in this file. The page is public and its links sign somebody in;
    // a real tenant appearing in this list is a real business's dashboard handed
    // to the internet.
    entryPointTenant('demo-fitnesz', isDemo: true);
    entryPointTenant('valodi-ceg', isDemo: false);

    $this->get('http://'.config('tenancy.central_domain'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('demo_personas', 1)
            ->where('demo_personas.0.slug', 'demo-fitnesz')
        );
});

it('leaves out the smoke tenant and anything not active', function () {
    entryPointTenant('demo-fitnesz', isDemo: true);
    // Exists to prove the seed framework runs, not to be shown to anybody
    // (docs/20 §3.2): one service and no story.
    entryPointTenant('demo-smoke', isDemo: true);
    entryPointTenant('demo-regi', isDemo: true, status: TenantStatus::Archived);

    $this->get('http://'.config('tenancy.central_domain'))
        ->assertInertia(fn (Assert $page) => $page->has('demo_personas', 1)
            ->where('demo_personas.0.slug', 'demo-fitnesz')
        );
});

it('renders the section with nothing at all seeded', function () {
    // An installation with no demo data must still serve the landing page —
    // the section renders nothing rather than four dead links.
    $this->get('http://'.config('tenancy.central_domain'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('demo_personas', 0));
});

// --- The links it hands out --------------------------------------------------

it('hands out an admin link that actually signs the visitor in', function () {
    $tenant = entryPointTenant('demo-fitnesz', isDemo: true);

    $adminUrl = $this->get('http://'.config('tenancy.central_domain'))
        ->viewData('page')['props']['demo_personas'][0]['admin_url'];

    // Followed for real rather than pattern-matched: a signature the landing
    // mints and the route rejects is exactly the failure a regex would miss.
    $this->get($adminUrl)->assertRedirect('/dashboard');

    $this->assertAuthenticated();
    expect(auth()->user()->tenant_id)->toBe($tenant->getKey());
});

it('⚠️ hands out a link that is dead a quarter of an hour later', function () {
    entryPointTenant('demo-fitnesz', isDemo: true);

    $adminUrl = $this->get('http://'.config('tenancy.central_domain'))
        ->viewData('page')['props']['demo_personas'][0]['admin_url'];

    // The link is minted per render, so a cached page would go on serving one
    // long after it stopped working. This is the assertion that says the expiry
    // is real, and the reason the page is not cached.
    Carbon::setTestNow(Carbon::now()->addMinutes(16));

    $this->get($adminUrl)->assertForbidden();

    $this->assertGuest();
});

it('tells the browser when the admin link stops working', function () {
    entryPointTenant('demo-fitnesz', isDemo: true);

    Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));

    $persona = $this->get('http://'.config('tenancy.central_domain'))
        ->viewData('page')['props']['demo_personas'][0];

    // The landing has to know this, not just the route: a tab left open past the
    // window would otherwise put a 403 inside the demo frame, which a visitor
    // reads as a broken product rather than an expired link.
    expect(Carbon::parse($persona['admin_url_expires_at'])->toIso8601String())
        ->toBe(Carbon::now()->addMinutes(15)->toIso8601String());
});

// --- Telling the visitor where they are --------------------------------------

it('⚠️ tells a visitor on a demo page that the data is fictional', function () {
    $demo = entryPointTenant('demo-fitnesz', isDemo: true);

    // The personas are written to look like real businesses — that realism is
    // the point of them, and it is why the page has to say what it is. The
    // banner reads this shared prop on every page of the tenant, not just the
    // one a visitor happens to land on first.
    $this->get('http://'.$demo->slug.'.'.config('tenancy.central_domain').'/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenant.is_demo', true));
});

it('says nothing of the sort on a real tenant', function () {
    $real = entryPointTenant('valodi-ceg', isDemo: false);

    $this->get('http://'.$real->slug.'.'.config('tenancy.central_domain').'/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenant.is_demo', false));
});

// --- The conversion point ----------------------------------------------------

it('offers a demo visitor a way to sign up after a completed booking', function () {
    $tenant = entryPointTenant('demo-fitnesz', isDemo: true);
    app(TenantManager::class)->set($tenant);

    $booking = demoConfirmedBooking($tenant);

    $this->get('http://'.$tenant->slug.'.'.config('tenancy.central_domain').'/booked/'.$booking->code)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Booked')
            // ⚠️ Absolute, to the central domain. `/register` on this subdomain
            // is where the fixture business's CUSTOMERS sign up — sending a
            // service provider there would create an account in the demo.
            ->where('register_url', rtrim((string) config('app.url'), '/').'/register')
        );
});

it('⚠️ never advertises the platform on a real tenant’s confirmation page', function () {
    // A paying tenant's confirmation page belongs to that tenant. slot4u putting
    // its own sign-up CTA in front of their customer is the platform competing
    // for attention on somebody else's shop floor (docs/19 §2).
    $tenant = entryPointTenant('valodi-ceg', isDemo: false);
    app(TenantManager::class)->set($tenant);

    $booking = demoConfirmedBooking($tenant);

    $this->get('http://'.$tenant->slug.'.'.config('tenancy.central_domain').'/booked/'.$booking->code)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('register_url', null));
});

/** A confirmed booking a guest could be looking at the confirmation page for. */
function demoConfirmedBooking(Tenant $tenant): Booking
{
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
    ]);

    return Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'customer_id' => null,
        'guest_name' => 'Teszt Vendég',
        'guest_email' => 'vendeg@example.test',
        'status' => BookingStatus::Confirmed,
        'starts_at' => Carbon::now()->addDays(3),
        'ends_at' => Carbon::now()->addDays(3)->addHour(),
    ]);
}
