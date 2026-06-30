<?php

use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

it('uses the tenant locale on a tenant domain', function () {
    Tenant::factory()->active()->create(['slug' => 'acme', 'locale' => 'en']);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('falls back to the app default locale on the central domain', function () {
    $this->get('http://'.config('tenancy.central_domain'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', config('app.locale')));
});

it('uses the authenticated user locale on the admin domain', function () {
    $admin = User::factory()->create(['tenant_id' => null, 'locale' => 'en']);

    $this->actingAs($admin)
        ->get(superUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('falls back to the app default when the user has no locale preference', function () {
    $admin = User::factory()->create(['tenant_id' => null, 'locale' => null]);

    $this->actingAs($admin)
        ->get(superUrl('/'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', config('app.locale')));
});

it('lets the tenant locale take precedence over the user locale', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'locale' => 'en']);
    $member = User::factory()->create(['tenant_id' => $tenant->id, 'locale' => 'hu']);

    $this->actingAs($member)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('exposes the app translations to the frontend as a shared prop', function () {
    $this->get('http://'.config('tenancy.central_domain'))
        ->assertInertia(fn (Assert $page) => $page->has('translations'));
});
