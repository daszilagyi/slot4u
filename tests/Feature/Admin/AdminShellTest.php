<?php

use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

it('renders the admin dashboard for a tenant user with tenant branding', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')
            ->where('tenant.name', 'Acme')
            ->where('tenant.slug', 'acme'));
});

it('renders the sample CRUD showcase for a tenant user', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->get(tenantHost('acme', '/showcase'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Showcase'));
});

it('requires authentication for the admin showcase', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/showcase'))->assertRedirectContains('/login');
});

it('exposes the tenant branding prop only inside tenant context', function () {
    // On the admin (central) domain there is no tenant, so the prop is null.
    $this->actingAs(superAdmin())
        ->get(superUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenant', null));
});
