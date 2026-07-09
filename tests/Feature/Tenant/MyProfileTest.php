<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function profileTenant(): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function profileCustomer(Tenant $tenant, array $attributes = []): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create([...$attributes, 'tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

it('renders the profile page with the customer data', function () {
    $tenant = profileTenant();
    $me = profileCustomer($tenant, ['name' => 'Kovács Anna', 'email' => 'anna@example.test', 'phone' => '+36301234567']);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/My/Profile')
            ->where('profile.name', 'Kovács Anna')
            ->where('profile.email', 'anna@example.test')
            ->where('profile.phone', '+36301234567'));
});

it('updates the customer name and phone', function () {
    $tenant = profileTenant();
    $me = profileCustomer($tenant, ['email' => 'anna@example.test']);

    $this->actingAs($me)
        ->from(tenantHost('acme', '/my/profile'))
        ->put(tenantHost('acme', '/my/profile'), ['name' => 'Új Név', 'phone' => '+36209999999'])
        ->assertRedirect(tenantHost('acme', '/my/profile'));

    $me->refresh();
    expect($me->name)->toBe('Új Név')
        ->and($me->phone)->toBe('+36209999999')
        // Email is read-only and untouched.
        ->and($me->email)->toBe('anna@example.test');
});

it('requires a name', function () {
    $tenant = profileTenant();
    $me = profileCustomer($tenant);

    $this->actingAs($me)
        ->from(tenantHost('acme', '/my/profile'))
        ->put(tenantHost('acme', '/my/profile'), ['name' => '', 'phone' => null])
        ->assertSessionHasErrors('name');
});

it('changes the password with the correct current password', function () {
    $tenant = profileTenant();
    $me = profileCustomer($tenant, ['password' => Hash::make('old-secret-123')]);

    $this->actingAs($me)
        ->from(tenantHost('acme', '/my/profile'))
        ->put(tenantHost('acme', '/my/password'), [
            'current_password' => 'old-secret-123',
            'password' => 'new-strong-456',
            'password_confirmation' => 'new-strong-456',
        ])
        ->assertRedirect(tenantHost('acme', '/my/profile'));

    expect(Hash::check('new-strong-456', $me->refresh()->password))->toBeTrue();
});

it('rejects a password change with the wrong current password', function () {
    $tenant = profileTenant();
    $me = profileCustomer($tenant, ['password' => Hash::make('old-secret-123')]);

    $this->actingAs($me)
        ->from(tenantHost('acme', '/my/profile'))
        ->put(tenantHost('acme', '/my/password'), [
            'current_password' => 'wrong-password',
            'password' => 'new-strong-456',
            'password_confirmation' => 'new-strong-456',
        ])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('old-secret-123', $me->refresh()->password))->toBeTrue();
});

it('rejects an unconfirmed new password', function () {
    $tenant = profileTenant();
    $me = profileCustomer($tenant, ['password' => Hash::make('old-secret-123')]);

    $this->actingAs($me)
        ->from(tenantHost('acme', '/my/profile'))
        ->put(tenantHost('acme', '/my/password'), [
            'current_password' => 'old-secret-123',
            'password' => 'new-strong-456',
            'password_confirmation' => 'mismatch',
        ])
        ->assertSessionHasErrors('password');
});

it('throttles the password change route', function () {
    $tenant = profileTenant();
    $me = profileCustomer($tenant, ['password' => Hash::make('old-secret-123')]);

    // 6 requests/min are allowed (wrong password → 302 back); the 7th is 429.
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($me)
            ->from(tenantHost('acme', '/my/profile'))
            ->put(tenantHost('acme', '/my/password'), ['current_password' => 'wrong'])
            ->assertStatus(302);
    }

    $this->actingAs($me)
        ->put(tenantHost('acme', '/my/password'), ['current_password' => 'wrong'])
        ->assertStatus(429);
});

it('forbids a staff user from the members profile', function () {
    $tenant = profileTenant();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $staff = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff->assignRole(Role::TenantAdmin->value);

    $this->actingAs($staff)
        ->get(tenantHost('acme', '/my/profile'))
        ->assertForbidden();
});

it('redirects a guest from the members profile to login', function () {
    profileTenant();

    $this->get(tenantHost('acme', '/my/profile'))
        ->assertRedirectContains('/login');
});
