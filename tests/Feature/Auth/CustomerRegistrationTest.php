<?php

use App\Actions\Customer\CreateCustomer;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** The customer registration payload for a tenant subdomain. */
function customerRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
        'phone' => '+36301234567',
        'password' => 'strong-password',
        'password_confirmation' => 'strong-password',
    ], $overrides);
}

it('renders the customer registration page on a tenant subdomain', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/RegisterCustomer'));
});

it('renders the tenant sign-up page on the central domain', function () {
    $this->get('http://'.config('tenancy.central_domain').'/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
});

it('registers a customer for the tenant and lands them in the members area', function () {
    Notification::fake();
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->post(tenantHost('acme', '/register'), customerRegistration())
        ->assertRedirect(tenantHost('acme', '/my/bookings'));

    $user = User::query()->where('email', 'anna@example.test')->sole();
    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->phone)->toBe('+36301234567');

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    expect($user->hasRole(Role::Customer->value))->toBeTrue();

    $this->assertAuthenticated();
    expect(auth()->id())->toBe($user->id);
});

it('does not create a tenant when a customer registers', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->post(tenantHost('acme', '/register'), customerRegistration());

    expect(Tenant::query()->count())->toBe(1);
});

it('rejects a duplicate email on customer registration', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'anna@example.test']);

    $this->from(tenantHost('acme', '/register'))
        ->post(tenantHost('acme', '/register'), customerRegistration())
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('refuses customer registration on a suspended tenant', function () {
    Tenant::factory()->create(['slug' => 'acme', 'status' => TenantStatus::Suspended]);

    $this->get(tenantHost('acme', '/register'))
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Suspended'));

    $this->post(tenantHost('acme', '/register'), customerRegistration())
        ->assertStatus(503);

    expect(User::query()->where('email', 'anna@example.test')->exists())->toBeFalse();
});

it('throttles the register route', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    // The register limiter allows 5/min per IP; the 6th request is rejected.
    for ($i = 0; $i < 5; $i++) {
        $this->post(tenantHost('acme', '/register'), [])->assertStatus(302);
    }

    $this->post(tenantHost('acme', '/register'), [])->assertStatus(429);
});

it('lets a customer created via CreateCustomer reach the members area after login', function () {
    // Regression (SLO-95): CreateCustomer builds a Customer instance; a real
    // login resolves the same row as a User. The customer role must be visible
    // across both morph types, or ensure.customer would 403 a genuine customer.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $customer = app(CreateCustomer::class)([
        'name' => 'Béla', 'email' => 'bela@example.test', 'phone' => null,
    ]);

    // Act as the row resolved through the users auth provider (a User instance).
    $this->actingAs(User::findOrFail($customer->getKey()))
        ->get(tenantHost('acme', '/my/bookings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/My/Bookings'));
});

it('lets a freshly registered customer reach the members area', function () {
    Notification::fake();
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->post(tenantHost('acme', '/register'), customerRegistration());

    $this->get(tenantHost('acme', '/my/bookings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/My/Bookings'));
});
