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

it('scopes a role assignment to the tenant team it was granted in', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->registrar->setPermissionsTeamId($a->id);
    $user = User::factory()->create(['tenant_id' => $a->id]);
    $user->assignRole(Role::Manager->value);

    // In its own tenant the manager role and permissions resolve.
    expect($user->hasRole(Role::Manager->value))->toBeTrue()
        ->and($user->hasPermissionTo(Permission::BookingView->value))->toBeTrue();

    // Under another tenant's team the same user has no roles/permissions.
    $this->registrar->setPermissionsTeamId($b->id);
    $fresh = User::find($user->id);

    expect($fresh->hasRole(Role::Manager->value))->toBeFalse()
        ->and($fresh->hasPermissionTo(Permission::BookingView->value))->toBeFalse();
});

it('seeds the four tenant roles for every new tenant', function () {
    $tenant = Tenant::factory()->create();

    $this->registrar->setPermissionsTeamId($tenant->id);

    $names = Spatie\Permission\Models\Role::query()
        ->where('tenant_id', $tenant->id)
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($names)->toBe(['customer', 'employee', 'manager', 'tenant-admin']);
});
