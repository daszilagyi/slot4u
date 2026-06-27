<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->registrar = app(PermissionRegistrar::class);
    $this->seed(PermissionSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * Create a user in a fresh tenant and assign a tenant role. The Tenant observer
 * seeds that tenant's roles on create.
 */
function userWithRole(Role $role): User
{
    $tenant = Tenant::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

it('grants every permission to the tenant admin', function () {
    $admin = userWithRole(Role::TenantAdmin);

    foreach (Permission::cases() as $permission) {
        expect($admin->hasPermissionTo($permission->value))->toBeTrue();
    }
});

it('grants each role exactly its matrix permissions', function (Role $role) {
    $user = userWithRole($role);

    $expected = collect($role->permissions())->map->value;

    foreach (Permission::cases() as $permission) {
        expect($user->hasPermissionTo($permission->value))
            ->toBe($expected->contains($permission->value));
    }
})->with([
    'manager' => [Role::Manager],
    'employee' => [Role::Employee],
    'customer' => [Role::Customer],
]);

it('denies the manager billing, settings and role management', function () {
    $manager = userWithRole(Role::Manager);

    expect($manager->hasPermissionTo(Permission::BillingView->value))->toBeFalse()
        ->and($manager->hasPermissionTo(Permission::BillingEdit->value))->toBeFalse()
        ->and($manager->hasPermissionTo(Permission::SettingsEdit->value))->toBeFalse()
        ->and($manager->hasPermissionTo(Permission::RoleManage->value))->toBeFalse()
        ->and($manager->hasPermissionTo(Permission::ServiceManage->value))->toBeFalse();
});

it('lets a direct permission override the role grant', function () {
    $manager = userWithRole(Role::Manager);

    expect($manager->hasPermissionTo(Permission::SettingsEdit->value))->toBeFalse();

    $manager->givePermissionTo(Permission::SettingsEdit->value);

    expect($manager->hasPermissionTo(Permission::SettingsEdit->value))->toBeTrue();
});
