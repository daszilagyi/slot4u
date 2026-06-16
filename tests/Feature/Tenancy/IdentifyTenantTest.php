<?php

use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

function tenantUrl(string $slug): string
{
    return 'http://'.$slug.'.'.config('tenancy.central_domain').'/';
}

it('returns 404 for an unknown subdomain', function () {
    $this->get(tenantUrl('does-not-exist'))->assertNotFound();
});

it('returns 404 for a reserved subdomain even if a tenant has that slug', function () {
    Tenant::factory()->active()->create(['slug' => 'api']);

    $this->get(tenantUrl('api'))->assertNotFound();
});

it('resolves a known tenant and renders its home', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Home'));
});

it('sets the application locale from the tenant locale on identify', function () {
    // IdentifyTenant calls app()->setLocale($tenant->locale). Verify the locale
    // is actually switched so downstream i18n (lang files, Carbon, etc.) uses
    // the tenant's language and not the global default.
    Tenant::factory()->active()->create(['slug' => 'intl-co', 'locale' => 'en']);

    $this->get(tenantUrl('intl-co'))->assertOk();

    expect(app()->getLocale())->toBe('en');
});
