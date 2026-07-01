<?php

use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function authTenantUrl(string $slug, string $path = '/'): string
{
    return 'http://'.$slug.'.'.config('tenancy.central_domain').$path;
}

function authAdminUrl(string $path = '/'): string
{
    return 'http://'.config('tenancy.admin_subdomain').'.'.config('tenancy.central_domain').$path;
}

it('lets a tenant user reach their own dashboard', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->get(authTenantUrl('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));
});

it('forbids a user from accessing another tenant\'s dashboard (403)', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $user = User::factory()->create(['tenant_id' => $other->id]);

    $this->actingAs($user)
        ->get(authTenantUrl('acme', '/dashboard'))
        ->assertForbidden();
});

it('redirects a super-admin away from a tenant dashboard to the admin panel', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->create(['tenant_id' => null]);

    $this->actingAs($superAdmin)
        ->get(authTenantUrl('acme', '/dashboard'))
        ->assertRedirect(authAdminUrl('/'));
});

it('redirects a guest from the tenant dashboard to login', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(authTenantUrl('acme', '/dashboard'))
        ->assertRedirectContains('/login');
});

it('lets a super-admin reach the admin panel', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);

    $this->actingAs($superAdmin)
        ->get(authAdminUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Super/Dashboard'));
});

it('forbids a tenant user from the admin panel (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->get(authAdminUrl('/'))
        ->assertForbidden();
});

it('redirects a guest from the admin panel to login', function () {
    $this->get(authAdminUrl('/'))->assertRedirectContains('/login');
});
