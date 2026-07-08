<?php

use App\Enums\Feature;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Tenancy\TenantManager;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function () {
    app(TenantManager::class)->forget();
});

it('renders the public home with active services grouped by category', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);
    $category = ServiceCategory::factory()->forTenant($tenant)->create(['name' => 'Masszázs', 'sort_order' => 1]);
    Service::factory()->forTenant($tenant)->create(['category_id' => $category->id, 'name' => 'Svédmasszázs', 'active' => true]);
    // An inactive service must not surface on the public page.
    Service::factory()->forTenant($tenant)->create(['category_id' => $category->id, 'name' => 'Rejtett', 'active' => false]);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Home')
            ->where('profile.name', 'Acme')
            ->has('categories', 1)
            ->where('categories.0.name', 'Masszázs')
            ->has('categories.0.services', 1)
            ->where('categories.0.services.0.name', 'Svédmasszázs'));
});

it('lists uncategorised services in a trailing bucket', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $category = ServiceCategory::factory()->forTenant($tenant)->create(['name' => 'Kategória', 'sort_order' => 1]);
    Service::factory()->forTenant($tenant)->create(['category_id' => $category->id, 'name' => 'Kategorizált', 'active' => true]);
    Service::factory()->forTenant($tenant)->create(['category_id' => null, 'name' => 'Önálló', 'active' => true]);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('categories', 2)
            ->where('categories.1.id', null)
            ->where('categories.1.services.0.name', 'Önálló'));
});

it('shows only active locations', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    Location::factory()->forTenant($tenant)->create(['name' => 'Belváros', 'active' => true]);
    Location::factory()->forTenant($tenant)->inactive()->create(['name' => 'Zárt telephely']);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('locations', 1)
            ->where('locations.0.name', 'Belváros'));
});

it('does not leak another tenant\'s services or locations', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    Service::factory()->forTenant($other)->create(['name' => 'Foreign', 'active' => true]);
    Location::factory()->forTenant($other)->create(['name' => 'Foreign HQ', 'active' => true]);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('categories', 0)
            ->has('locations', 0));
});

it('hides the cover image when feature_branding is disabled (base plan default)', function () {
    Tenant::factory()->active()->create([
        'slug' => 'acme',
        'branding' => ['cover_path' => 'tenants/1/cover.jpg', 'primary_color' => '#123456'],
    ]);

    // A cover path is stored, but branding is off by default → not exposed.
    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('branding.cover_url', null));
});

it('shows the cover image when feature_branding is enabled', function () {
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'branding' => ['cover_path' => 'tenants/1/cover.jpg', 'primary_color' => '#123456'],
    ]);

    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::Branding, 'enabled' => true]);
    app(TenantManager::class)->forget();

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('branding.cover_url', fn (?string $url) => is_string($url) && $url !== ''));
});

it('exposes the company profile from tenant settings', function () {
    Tenant::factory()->active()->create([
        'slug' => 'acme',
        'name' => 'Acme',
        'settings' => [
            'description' => 'A legjobb szalon a városban',
            'email' => 'hello@acme.test',
            'phone' => '+3611234567',
            'address_line' => 'Fő utca 1.',
            'address_city' => 'Budapest',
            'address_postal' => '1051',
        ],
    ]);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.description', 'A legjobb szalon a városban')
            ->where('profile.email', 'hello@acme.test')
            ->where('profile.address.city', 'Budapest')
            ->where('profile.address.postal_code', '1051'));
});
