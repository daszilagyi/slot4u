<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A tenant user with the given role, in team context so role checks resolve. */
function accessUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

it('walls the customer role off from the tenant admin panel (SLO-86)', function (string $path) {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = accessUser($tenant, Role::Customer);

    $this->actingAs($customer)
        ->get(tenantHost('acme', $path))
        ->assertForbidden();
})->with([
    'dashboard (no can: gate)' => ['/dashboard'],
    'own-profile (no can: gate)' => ['/profile'],
    'customers list' => ['/customers'],
    'bookings list' => ['/bookings'],
]);

it('blocks a customer from writing through the admin panel', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = accessUser($tenant, Role::Customer);

    $this->actingAs($customer)
        ->post(tenantHost('acme', '/customers'), [
            'name' => 'Hijack',
            'email' => 'hijack@example.test',
        ])
        ->assertForbidden();
});

it('blocks a role-less tenant user from the admin panel', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    // No role at all → not staff → 403 (defense-in-depth beyond the customer role).
    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertForbidden();
});

it('lets a user who is both customer and staff into the admin panel', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = accessUser($tenant, Role::Customer);
    $user->assignRole(Role::Employee->value);

    // The staff role wins: a dual-role user still reaches the admin panel.
    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk();
});

it('lets staff roles into the admin panel', function (Role $role) {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = accessUser($tenant, $role);

    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk();
})->with([
    'tenant-admin' => [Role::TenantAdmin],
    'manager' => [Role::Manager],
    'employee' => [Role::Employee],
]);

it('still lets a customer reach the public tenant pages', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = accessUser($tenant, Role::Customer);

    // ensure.staff guards only the admin group, not the public surface.
    $this->actingAs($customer)
        ->get(tenantHost('acme', '/'))
        ->assertOk();
});

it('grants the customer role no admin permission codes', function () {
    expect(Role::Customer->permissions())->toBe([]);
});
