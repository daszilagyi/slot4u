<?php

use App\Enums\PlanLimitKey;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Services\Plan\PlanLimitService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    // Base plan limit: max_employees (docs/10 §15.2). Filled up to whatever the
    // cap currently is, so raising it (SLO-195) does not turn this into a test
    // of a number nobody meant to pin.
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A tenant-admin user in the given tenant (has staff.manage). */
function staffAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);

    return $user;
}

it('lists staff with plan limits', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    Staff::factory()->forTenant($tenant)->create(['name' => 'Anna']);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/staff'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Staff/Index')
            ->has('staff', 1)
            ->where('staff.0.name', 'Anna')
            // What is asserted is that the screen shows the REAL ceiling, not
            // that the ceiling is any particular number.
            ->where('limits.employees.max', app(PlanLimitService::class)->limitFor(PlanLimitKey::MaxEmployees)));
});

it('creates a staff member stamped with the current tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/staff'), [
            'name' => 'Béla',
            'title' => 'Fodrász',
            'color' => '#112233',
            'active' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('staff', [
        'tenant_id' => $tenant->id,
        'name' => 'Béla',
        'user_id' => null,
    ]);
});

it('invites an employee login user when an email is supplied', function () {
    Notification::fake();
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/staff'), [
            'name' => 'Csilla',
            'color' => '#445566',
            'active' => true,
            'email' => 'csilla@example.com',
        ])
        ->assertRedirect();

    $staff = Staff::where('name', 'Csilla')->firstOrFail();
    expect($staff->user_id)->not->toBeNull();

    $invited = User::findOrFail($staff->user_id);
    expect($invited->tenant_id)->toBe($tenant->id)
        ->and($invited->email)->toBe('csilla@example.com')
        ->and($invited->email_verified_at)->not->toBeNull();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    expect($invited->hasRole(Role::Employee->value))->toBeTrue()
        ->and($invited->hasRole(Role::TenantAdmin->value))->toBeFalse();

    Notification::assertSentTo($invited, StaffInvitationNotification::class);
});

it('rejects an invitation email already used by another user', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/staff'), [
            'name' => 'Dóra',
            'color' => '#778899',
            'active' => true,
            'email' => 'taken@example.com',
        ])
        ->assertSessionHasErrors('email');

    expect(Staff::where('name', 'Dóra')->exists())->toBeFalse();
});

it('updates a staff member', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create(['name' => 'Old']);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/staff/{$staff->id}"), [
            'name' => 'New',
            'color' => '#000000',
            'active' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('staff', ['id' => $staff->id, 'name' => 'New', 'active' => false]);
});

it('enforces the max_employees plan limit', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);

    $cap = app(PlanLimitService::class)->limitFor(PlanLimitKey::MaxEmployees);
    Staff::factory()->count($cap)->forTenant($tenant)->create(); // exactly at the cap

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/staff'), ['name' => 'One too many', 'color' => '#123456', 'active' => true])
        ->assertSessionHasErrors('name');

    expect(Staff::count())->toBe($cap);
});

it('still allows a staff member while there is headroom under the cap', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);

    $cap = app(PlanLimitService::class)->limitFor(PlanLimitKey::MaxEmployees);
    Staff::factory()->count($cap - 1)->forTenant($tenant)->create();

    // The other half of the guard: a raised limit has to actually admit the
    // extra staff, not merely refuse later than before.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/staff'), ['name' => 'Last one that fits', 'color' => '#123456', 'active' => true])
        ->assertRedirect();

    expect(Staff::count())->toBe($cap);
});

it('deletes a staff member with no bookings', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/staff/{$staff->id}"))
        ->assertRedirect();

    $this->assertDatabaseMissing('staff', ['id' => $staff->id]);
});

it('404s when editing another tenant\'s staff (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = Staff::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/staff/{$foreign->id}"), ['name' => 'Hijack', 'color' => '#ffffff', 'active' => true])
        ->assertNotFound();

    $this->assertDatabaseMissing('staff', ['id' => $foreign->id, 'name' => 'Hijack']);
});

it('404s when deleting another tenant\'s staff (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = Staff::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/staff/{$foreign->id}"))
        ->assertNotFound();

    $this->assertDatabaseHas('staff', ['id' => $foreign->id]);
});

it('only lists the current tenant\'s staff', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    Staff::factory()->forTenant($tenant)->create(['name' => 'Mine']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    Staff::factory()->forTenant($other)->create(['name' => 'Theirs']);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/staff'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('staff', 1)
            ->where('staff.0.name', 'Mine'));
});

it('forbids a manager without staff.manage (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $manager = User::factory()->create(['tenant_id' => $tenant->id]);
    $manager->assignRole(Role::Manager->value);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/staff'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/staff'))->assertRedirectContains('/login');
});

it('lets an employee edit their own profile without staff.manage', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee = User::factory()->create(['tenant_id' => $tenant->id]);
    $employee->assignRole(Role::Employee->value);
    $staff = Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id, 'name' => 'Self']);

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Profile/Edit')
            ->where('staff.name', 'Self'));

    $this->actingAs($employee)
        ->put(tenantHost('acme', '/profile'), ['name' => 'Renamed', 'title' => 'Masszőr', 'color' => '#abcdef'])
        ->assertRedirect();

    $this->assertDatabaseHas('staff', ['id' => $staff->id, 'name' => 'Renamed', 'title' => 'Masszőr']);
});

it('shows an empty profile for a user with no linked staff', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant); // tenant-admin, no staff record

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Profile/Edit')
            ->where('staff', null));

    // With no linked staff there is nothing to update → 404.
    $this->actingAs($admin)
        ->put(tenantHost('acme', '/profile'), ['name' => 'X', 'color' => '#000000'])
        ->assertNotFound();
});

it('assigns locations to a staff member on create (SLO-51)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    $locA = Location::factory()->forTenant($tenant)->create();
    $locB = Location::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/staff'), [
            'name' => 'Cecil',
            'color' => '#445566',
            'active' => true,
            'location_ids' => [$locA->id, $locB->id],
        ])
        ->assertRedirect();

    $staff = Staff::where('name', 'Cecil')->firstOrFail();
    expect($staff->locations->pluck('id')->sort()->values()->all())
        ->toBe([$locA->id, $locB->id]);
});

it('re-syncs staff locations on update', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $locA = Location::factory()->forTenant($tenant)->create();
    $locB = Location::factory()->forTenant($tenant)->create();
    $staff->locations()->sync([$locA->id]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/staff/{$staff->id}"), [
            'name' => $staff->name,
            'color' => $staff->color,
            'active' => true,
            'location_ids' => [$locB->id],
        ])
        ->assertRedirect();

    expect($staff->fresh()->locations->pluck('id')->all())->toBe([$locB->id]);
});

it('rejects a location from another tenant in the staff assignment', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignLocation = Location::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/staff'), [
            'name' => 'Dóra',
            'color' => '#778899',
            'active' => true,
            'location_ids' => [$foreignLocation->id],
        ])
        ->assertSessionHasErrors('location_ids.0');
});

it('keeps existing staff locations on a partial update that omits location_ids', function () {
    // A payload without the location_ids key (e.g. a future PATCH) must NOT clear
    // the assignments — only an explicit key re-syncs them (UpdateStaff guard).
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = staffAdmin($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $location = Location::factory()->forTenant($tenant)->create();
    $staff->locations()->sync([$location->id]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/staff/{$staff->id}"), [
            'name' => 'Renamed',
            'color' => $staff->color,
            'active' => true,
            // no location_ids key
        ])
        ->assertRedirect();

    expect($staff->fresh()->locations->pluck('id')->all())->toBe([$location->id]);
});

it('does not expose a foreign-tenant location on a staff (read isolation)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignLocation = Location::factory()->forTenant($other)->create();

    // Forge a pivot row linking our staff to another tenant's location.
    DB::table('staff_locations')->insert([
        'staff_id' => $staff->id,
        'location_id' => $foreignLocation->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // In the current tenant context the Location global scope filters it out.
    app(TenantManager::class)->set($tenant);
    expect($staff->fresh()->locations->pluck('id')->all())->toBe([]);
    app(TenantManager::class)->forget();
});
