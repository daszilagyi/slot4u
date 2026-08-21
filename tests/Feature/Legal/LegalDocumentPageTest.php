<?php

use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Reading a legal document (SLO-161)
|--------------------------------------------------------------------------
|
| Public by necessity: nobody can consent to a text they are not allowed to
| read, and that includes before they have an account. What is not public is
| another tenant's text.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

it('shows a tenant document to a signed-out visitor on that tenant host', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $document = LegalDocument::factory()->forTenant($tenant)->privacy()
        ->version('1.0')->create(['title' => 'Adatkezelési tájékoztató']);

    $this->get(tenantHost('acme', '/legal/'.$document->id))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Legal/Show')
            ->where('document.version', '1.0')
            ->where('document.title', 'Adatkezelési tájékoztató'));
});

it('404s on another tenant document rather than confirming it exists', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = LegalDocument::factory()->forTenant($other)->privacy()->create();

    $this->get(tenantHost('acme', '/legal/'.$foreign->id))->assertNotFound();
});

it('shows a platform document on a tenant host too', function () {
    // A tenant's staff accept slot4u's documents on the same subdomain their
    // customers accept the tenant's.
    Tenant::factory()->active()->create(['slug' => 'acme']);
    $platform = LegalDocument::factory()->platform()->terms()->create();

    $this->get(tenantHost('acme', '/legal/'.$platform->id))->assertSuccessful();
});

it('shows a platform document on the central domain', function () {
    $platform = LegalDocument::factory()->platform()->terms()->create();

    $this->get('http://'.config('tenancy.central_domain').'/legal/'.$platform->id)
        ->assertSuccessful();
});

it('404s on a tenant document from the central domain', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $document = LegalDocument::factory()->forTenant($tenant)->privacy()->create();

    $this->get('http://'.config('tenancy.central_domain').'/legal/'.$document->id)
        ->assertNotFound();
});

it('keeps a superseded version readable', function () {
    // A consent record naming version 1.0 proves nothing if 1.0 can no longer
    // be seen.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $old = LegalDocument::factory()->forTenant($tenant)->privacy()
        ->version('1.0')->effectiveAt(now()->subYear())->create();
    LegalDocument::factory()->forTenant($tenant)->privacy()
        ->version('2.0')->effectiveAt(now()->subDay())->create();

    $this->get(tenantHost('acme', '/legal/'.$old->id))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('document.version', '1.0'));
});

it('sends a linked document to where it actually lives', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $document = LegalDocument::factory()->forTenant($tenant)->privacy()
        ->linked('https://acme.test/adatkezeles')->create();

    $this->get(tenantHost('acme', '/legal/'.$document->id))
        ->assertRedirect('https://acme.test/adatkezeles');
});

it('shares the documents in force with every page on the host', function () {
    // How six different forms show the same tick box without six controllers
    // passing it.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $document = LegalDocument::factory()->forTenant($tenant)->privacy()->create();
    LegalDocument::factory()->forTenant($tenant)->privacy()->version('next')->draft()->create();

    $this->get(tenantHost('acme', '/book'))
        ->assertInertia(fn ($page) => $page
            ->has('legal.documents', 1)
            ->where('legal.documents.0.id', $document->id)
            ->where('legal.ids', [$document->id]));
});

it('shares the platform documents on the central domain', function () {
    $terms = LegalDocument::factory()->platform()->terms()->create();

    $this->get('http://'.config('tenancy.central_domain').'/register')
        ->assertInertia(fn ($page) => $page->where('legal.ids', [$terms->id]));
});
