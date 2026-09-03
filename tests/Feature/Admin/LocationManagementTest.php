<?php

use App\Enums\PlanLimitKey;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plan\PlanLimitService;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    // Base plan limits: max_locations and max_rooms (docs/10 §15.2). The
    // enforcement tests below fill up to whatever the cap currently is rather
    // than restating it, so raising it (SLO-195) moves the ceiling without
    // turning these into tests of a number nobody meant to pin.
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A tenant-admin user in the given tenant (has location.manage). */
function locationAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);

    return $user;
}

it('lists locations with their rooms and plan limits', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $location = Location::factory()->forTenant($tenant)->create(['name' => 'Main']);
    Room::factory()->forLocation($location)->create();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/locations'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Locations/Index')
            ->has('locations', 1)
            ->where('locations.0.name', 'Main')
            ->has('locations.0.rooms', 1)
            // What is asserted is that the screen shows the REAL ceilings, not
            // that they are any particular numbers.
            ->where('limits.locations.max', app(PlanLimitService::class)->limitFor(PlanLimitKey::MaxLocations))
            ->where('limits.rooms.max', app(PlanLimitService::class)->limitFor(PlanLimitKey::MaxRooms)));
});

it('creates a location stamped with the current tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/locations'), [
            'name' => 'Downtown',
            'address' => ['line' => 'Fő út 1', 'city' => 'Budapest', 'postal_code' => '1011'],
            'phone' => '+36 1 234 5678',
            'active' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('locations', ['tenant_id' => $tenant->id, 'name' => 'Downtown']);
});

it('updates a location', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $location = Location::factory()->forTenant($tenant)->create(['name' => 'Old']);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/locations/{$location->id}"), ['name' => 'New', 'active' => false])
        ->assertRedirect();

    $this->assertDatabaseHas('locations', ['id' => $location->id, 'name' => 'New', 'active' => false]);
});

it('enforces the max_locations plan limit', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);

    $cap = app(PlanLimitService::class)->limitFor(PlanLimitKey::MaxLocations);
    Location::factory()->count($cap)->forTenant($tenant)->create(); // exactly at the cap

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/locations'), ['name' => 'One too many', 'active' => true])
        ->assertSessionHasErrors('name');

    expect(Location::count())->toBe($cap);
});

it('still allows a location while there is headroom under the cap', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);

    $cap = app(PlanLimitService::class)->limitFor(PlanLimitKey::MaxLocations);
    Location::factory()->count($cap - 1)->forTenant($tenant)->create();

    // The other half of the guard: raising a limit must actually raise it. A
    // cap-only test would still pass against a plan that allowed nothing.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/locations'), ['name' => 'Last one that fits', 'active' => true])
        ->assertRedirect();

    expect(Location::count())->toBe($cap);
});

it('creates a room under a location', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $location = Location::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/locations/{$location->id}/rooms"), [
            'name' => 'Sauna',
            'type' => 'equipment',
            'capacity' => 4,
            'active' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rooms', [
        'tenant_id' => $tenant->id,
        'location_id' => $location->id,
        'name' => 'Sauna',
        'type' => 'equipment',
        'capacity' => 4,
    ]);
});

it('enforces the max_rooms plan limit', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $location = Location::factory()->forTenant($tenant)->create();

    $cap = app(PlanLimitService::class)->limitFor(PlanLimitKey::MaxRooms);
    Room::factory()->count($cap)->forLocation($location)->create(); // exactly at the cap

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/locations/{$location->id}/rooms"), [
            'name' => 'One too many',
            'type' => 'room',
            'capacity' => 1,
            'active' => true,
        ])
        ->assertSessionHasErrors('name');

    expect(Room::count())->toBe($cap);
});

it('deletes a room that has no bookings', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $location = Location::factory()->forTenant($tenant)->create();
    $room = Room::factory()->forLocation($location)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/rooms/{$room->id}"))
        ->assertRedirect();

    $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
});

it('blocks deleting a location that still has rooms', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $location = Location::factory()->forTenant($tenant)->create();
    Room::factory()->forLocation($location)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/locations/{$location->id}"))
        ->assertSessionHasErrors('delete');

    $this->assertDatabaseHas('locations', ['id' => $location->id]);
});

it('deletes an empty location', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $location = Location::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/locations/{$location->id}"))
        ->assertRedirect();

    $this->assertDatabaseMissing('locations', ['id' => $location->id]);
});

it('404s when editing another tenant\'s location (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = Location::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/locations/{$foreign->id}"), ['name' => 'Hijack', 'active' => true])
        ->assertNotFound();

    $this->assertDatabaseMissing('locations', ['id' => $foreign->id, 'name' => 'Hijack']);
});

it('404s when editing another tenant\'s room (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignLocation = Location::factory()->forTenant($other)->create();
    $foreignRoom = Room::factory()->forLocation($foreignLocation)->create();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/rooms/{$foreignRoom->id}"), [
            'name' => 'Hijack',
            'type' => 'room',
            'capacity' => 1,
            'active' => true,
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('rooms', ['id' => $foreignRoom->id, 'name' => 'Hijack']);
});

it('404s when adding a room to another tenant\'s location (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignLocation = Location::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/locations/{$foreignLocation->id}/rooms"), [
            'name' => 'Sneak',
            'type' => 'room',
            'capacity' => 1,
            'active' => true,
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('rooms', ['name' => 'Sneak']);
});

it('only lists the current tenant\'s locations', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = locationAdmin($tenant);
    Location::factory()->forTenant($tenant)->create(['name' => 'Mine']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    Location::factory()->forTenant($other)->create(['name' => 'Theirs']);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/locations'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('locations', 1)
            ->where('locations.0.name', 'Mine'));
});

it('forbids a manager without location.manage (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $manager = User::factory()->create(['tenant_id' => $tenant->id]);
    $manager->assignRole(Role::Manager->value);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/locations'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/locations'))->assertRedirectContains('/login');
});
