<?php

use App\Enums\Feature;
use App\Enums\QuoteRequestStatus;
use App\Enums\Role;
use App\Models\Event;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php. BasePlanSeeder switches both
// feature_waitlist and feature_quote_request on for the base plan.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 00:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** Active tenant, current + team context. */
function myReqTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function myReqCustomer(Tenant $tenant, array $attributes = []): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create([...$attributes, 'tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

it('lists only the customer\'s own waitlist positions with position and status', function () {
    $tenant = myReqTenant();
    $me = myReqCustomer($tenant);
    $other = myReqCustomer($tenant);

    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Jóga workshop']);
    $event = Event::factory()->forTenant($tenant)->at('2026-09-10 18:00:00', '2026-09-10 19:00:00')
        ->create(['service_id' => $service->id]);

    $mine = WaitlistEntry::factory()->forEvent($event)->position(3)
        ->create(['customer_id' => $me->id, 'service_id' => $service->id]);
    $foreign = WaitlistEntry::factory()->forEvent($event)->position(4)
        ->create(['customer_id' => $other->id, 'service_id' => $service->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/waitlist'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/My/Waitlist')
            ->has('entries', 1)
            ->where('entries.0.id', $mine->id)
            ->where('entries.0.service_name', 'Jóga workshop')
            // Stored UTC, shown in the tenant tz (Europe/Budapest, CEST +2 in Sept).
            ->where('entries.0.event_starts_local', '2026-09-10 20:00')
            ->where('entries.0.position', 3)
            ->where('entries.0.status', 'waiting')
            ->where('entries.0.offered_until_local', null));

    expect($foreign->customer_id)->not->toBe($me->id);
});

it('exposes the offer deadline only for an offered entry', function () {
    $tenant = myReqTenant();
    $me = myReqCustomer($tenant);

    $service = Service::factory()->forTenant($tenant)->create();
    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id]);

    WaitlistEntry::factory()->forEvent($event)->offered('2026-09-05 12:00:00')->position(1)
        ->create(['customer_id' => $me->id, 'service_id' => $service->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/waitlist'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.status', 'offered')
            // UTC → Europe/Budapest (CEST +2 in Sept).
            ->where('entries.0.offered_until_local', '2026-09-05 14:00'));
});

it('never lists a waitlist entry that belongs to another tenant', function () {
    $tenant = myReqTenant();
    $me = myReqCustomer($tenant);

    $other = Tenant::factory()->active()->create(['slug' => 'globex']);
    // Create the foreign rows *in the foreign tenant's context*, otherwise the
    // BelongsToTenant creating-hook would stamp them with the acme tenant id.
    app(TenantManager::class)->set($other);
    $otherService = Service::factory()->forTenant($other)->create();
    $otherEvent = Event::factory()->forTenant($other)->create(['service_id' => $otherService->id]);
    // Same customer id value on the foreign tenant's entry — the global scope,
    // not the customer filter, must keep it out.
    WaitlistEntry::factory()->forEvent($otherEvent)
        ->create(['customer_id' => $me->id, 'service_id' => $otherService->id]);

    // Restore the acting tenant's context for the request.
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/waitlist'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

it('403s the waitlist section when the feature is off', function () {
    $tenant = myReqTenant();
    $me = myReqCustomer($tenant);
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::Waitlist,
        'enabled' => false,
    ]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/waitlist'))
        ->assertForbidden();
});

it('lists the customer\'s own quote requests with service, status and price', function () {
    $tenant = myReqTenant();
    $me = myReqCustomer($tenant);
    $other = myReqCustomer($tenant);

    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Esküvői dekoráció']);

    $mine = QuoteRequest::factory()->forTenant($tenant)->quoted(250000, 'HUF')
        ->create([
            'service_id' => $service->id,
            'customer_id' => $me->id,
            'internal_notes' => 'SECRET-ADMIN-NOTE-do-not-leak',
            'parameters' => ['guests' => '120', 'secret_param' => 'LEAK-CANARY'],
        ]);
    QuoteRequest::factory()->forTenant($tenant)
        ->create(['service_id' => $service->id, 'customer_id' => $other->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/quotes'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/My/Quotes')
            ->has('quotes', 1)
            ->where('quotes.0.id', $mine->id)
            ->where('quotes.0.service_name', 'Esküvői dekoráció')
            ->where('quotes.0.status', 'quoted')
            ->where('quotes.0.price_minor', 250000)
            ->where('quotes.0.currency', 'HUF')
            // Present + tenant-localised; the exact value crosses a DST/year edge.
            ->where('quotes.0.valid_until_local', fn (?string $v) => $v !== null)
            // The admin's working notes + the raw parameters are never exposed.
            ->missing('quotes.0.internal_notes')
            ->missing('quotes.0.parameters'));
});

it('never leaks the internal note in the quotes response body', function () {
    $tenant = myReqTenant();
    $me = myReqCustomer($tenant);

    $service = Service::factory()->forTenant($tenant)->create();
    QuoteRequest::factory()->forTenant($tenant)->quoted()
        ->create([
            'service_id' => $service->id,
            'customer_id' => $me->id,
            'internal_notes' => 'SECRET-ADMIN-NOTE-do-not-leak',
        ]);

    $response = $this->actingAs($me)->get(tenantHost('acme', '/my/quotes'));

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRET-ADMIN-NOTE-do-not-leak');
});

it('shows a new quote request with no price yet', function () {
    $tenant = myReqTenant();
    $me = myReqCustomer($tenant);

    $service = Service::factory()->forTenant($tenant)->create();
    QuoteRequest::factory()->forTenant($tenant)->status(QuoteRequestStatus::New)
        ->create(['service_id' => $service->id, 'customer_id' => $me->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/quotes'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('quotes.0.status', 'new')
            ->where('quotes.0.price_minor', null)
            ->where('quotes.0.currency', null)
            ->where('quotes.0.valid_until_local', null));
});

it('403s the quotes section when the feature is off', function () {
    $tenant = myReqTenant();
    $me = myReqCustomer($tenant);
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::QuoteRequest,
        'enabled' => false,
    ]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/quotes'))
        ->assertForbidden();
});

it('forbids a staff user from the waitlist and quotes sections', function () {
    $tenant = myReqTenant();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $staff = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff->assignRole(Role::TenantAdmin->value);

    $this->actingAs($staff)->get(tenantHost('acme', '/my/waitlist'))->assertForbidden();
    $this->actingAs($staff)->get(tenantHost('acme', '/my/quotes'))->assertForbidden();
});

it('redirects a guest from the members requests sections to login', function () {
    myReqTenant();

    $this->get(tenantHost('acme', '/my/waitlist'))->assertRedirectContains('/login');
    $this->get(tenantHost('acme', '/my/quotes'))->assertRedirectContains('/login');
});
