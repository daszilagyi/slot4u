<?php

use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the login page', function () {
    $this->get('http://'.config('tenancy.central_domain').'/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('logs a tenant user in and redirects to their subdomain dashboard', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'admin@acme.test',
    ]);

    $this->post('http://acme.'.config('tenancy.central_domain').'/login', [
        'email' => 'admin@acme.test',
        'password' => 'password',
    ])->assertRedirect('http://acme.'.config('tenancy.central_domain').'/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('redirects a super-admin to the admin panel after login', function () {
    $superAdmin = User::factory()->create([
        'tenant_id' => null,
        'email' => 'root@slot4u.test',
    ]);

    $this->post('http://'.config('tenancy.central_domain').'/login', [
        'email' => 'root@slot4u.test',
        'password' => 'password',
    ])->assertRedirect('http://'.config('tenancy.admin_subdomain').'.'.config('tenancy.central_domain').'/');

    $this->assertAuthenticatedAs($superAdmin);
});

it('rejects invalid credentials', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'admin@acme.test',
    ]);

    $this->post('http://acme.'.config('tenancy.central_domain').'/login', [
        'email' => 'admin@acme.test',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('does not expose self-service registration (SLO-76)', function () {
    // Fortify's generic registration is disabled; registration is a custom
    // tenant-aware flow (SLO-76).
    $this->get('http://'.config('tenancy.central_domain').'/register')->assertNotFound();
    $this->post('http://'.config('tenancy.central_domain').'/register', [])->assertNotFound();
});

it('logs the user out', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->post('http://acme.'.config('tenancy.central_domain').'/logout')
        ->assertRedirect();

    $this->assertGuest();
});
