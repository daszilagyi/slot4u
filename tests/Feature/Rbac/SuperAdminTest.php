<?php

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

it('identifies a tenant-less user as super-admin', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);

    expect($superAdmin->isSuperAdmin())->toBeTrue();
});

it('bypasses every permission check via Gate::before', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);

    foreach (Permission::cases() as $permission) {
        expect(Gate::forUser($superAdmin)->allows($permission->value))->toBeTrue();
    }
});

it('does not treat a tenant user as super-admin', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    expect($user->isSuperAdmin())->toBeFalse()
        ->and(Gate::forUser($user)->allows(Permission::SettingsEdit->value))->toBeFalse();
});
