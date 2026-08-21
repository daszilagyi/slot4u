<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\LegalConsent;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Publishing legal documents (SLO-161)
|--------------------------------------------------------------------------
|
| Two scopes, two owners: the tenant writes what its customers accept, and only
| slot4u touches what tenants accept. The rule both share is that a version in
| force is never edited — new wording is a new row.
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

function legalAdmin(Tenant $tenant): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

/** A staff member with every ability except privacy.manage. */
function legalStaffWithoutPermission(Tenant $tenant): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user->assignRole(Role::Employee->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

/**
 * @return array<string, mixed>
 */
function legalPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'privacy',
        'version' => '1.0',
        'title' => 'Adatkezelési tájékoztató',
        'body' => 'Ezt a szöveget a bérlő írja.',
        'url' => '',
        'effective_from' => now()->toDateString(),
    ], $overrides);
}

it('publishes a version for the tenant that published it', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(legalAdmin($tenant))
        ->post(tenantHost('acme', '/settings/legal'), legalPayload())
        ->assertRedirect();

    $document = LegalDocument::query()->sole();

    expect($document->tenant_id)->toBe($tenant->id)
        ->and($document->version)->toBe('1.0')
        ->and($document->url)->toBeNull();
});

it('refuses a staff member without privacy.manage', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = legalStaffWithoutPermission($tenant);

    expect($user->can(Permission::PrivacyManage->value))->toBeFalse();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/settings/legal'))
        ->assertForbidden();
});

it('refuses a second version with a name already used for that type', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    LegalDocument::factory()->forTenant($tenant)->privacy()->version('1.0')->create();

    $this->actingAs(legalAdmin($tenant))
        ->from(tenantHost('acme', '/settings/legal'))
        ->post(tenantHost('acme', '/settings/legal'), legalPayload(['version' => '1.0']))
        ->assertSessionHasErrors('version');
});

it('allows the same version name for the other type', function () {
    // "1.0" of the terms and "1.0" of the notice are two different documents.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    LegalDocument::factory()->forTenant($tenant)->privacy()->version('1.0')->create();

    $this->actingAs(legalAdmin($tenant))
        ->post(tenantHost('acme', '/settings/legal'), legalPayload([
            'type' => 'terms',
            'version' => '1.0',
        ]))
        ->assertSessionHasNoErrors();
});

it('refuses a version that is neither a text nor a link', function () {
    // An empty page people are asked to accept is worse than asking nothing.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(legalAdmin($tenant))
        ->from(tenantHost('acme', '/settings/legal'))
        ->post(tenantHost('acme', '/settings/legal'), legalPayload(['body' => '', 'url' => '']))
        ->assertSessionHasErrors('body');
});

it('keeps a link-only version as a link, without a stray body', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(legalAdmin($tenant))
        ->post(tenantHost('acme', '/settings/legal'), legalPayload([
            'body' => '',
            'url' => 'https://acme.test/adatkezeles',
        ]))->assertRedirect();

    $document = LegalDocument::query()->sole();

    expect($document->url)->toBe('https://acme.test/adatkezeles')
        ->and($document->body)->toBeNull();
});

it('withdraws a version nobody has accepted', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $document = LegalDocument::factory()->forTenant($tenant)->privacy()->create();

    $this->actingAs(legalAdmin($tenant))
        ->delete(tenantHost('acme', '/settings/legal/'.$document->id))
        ->assertRedirect();

    expect(LegalDocument::query()->count())->toBe(0);
});

it('refuses to withdraw a version somebody has accepted', function () {
    // A consented version is evidence. The policy refuses first; the foreign key
    // would refuse again.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $document = LegalDocument::factory()->forTenant($tenant)->privacy()->create();
    LegalConsent::factory()->forTenant($tenant)->forDocument($document)->create();

    $this->actingAs(legalAdmin($tenant))
        ->delete(tenantHost('acme', '/settings/legal/'.$document->id))
        ->assertForbidden();

    expect(LegalDocument::query()->count())->toBe(1);
});

it('404s on another tenant document rather than confirming it exists', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = LegalDocument::factory()->forTenant($other)->privacy()->create();

    $this->actingAs(legalAdmin($tenant))
        ->delete(tenantHost('acme', '/settings/legal/'.$foreign->id))
        ->assertNotFound();
});

it('404s on a platform document from a tenant panel', function () {
    // The tenant panel owns the tenant's text and nothing else; the platform's
    // is not the tenant's to withdraw.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $platform = LegalDocument::factory()->platform()->terms()->create();
    $admin = legalAdmin($tenant);
    // Accept it first, or the re-acceptance gate answers before the route does
    // and the assertion measures the gate instead of the scoping.
    LegalConsent::factory()->forTenant($tenant)->forDocument($platform)->byUser($admin)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', '/settings/legal/'.$platform->id))
        ->assertNotFound();
});

it('lists only this tenant own documents', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $mine = LegalDocument::factory()->forTenant($tenant)->privacy()->create();
    LegalDocument::factory()->forTenant($other)->privacy()->create();
    $platform = LegalDocument::factory()->platform()->terms()->create();
    $admin = legalAdmin($tenant);
    LegalConsent::factory()->forTenant($tenant)->forDocument($platform)->byUser($admin)->create();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/legal'))
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Legal/Index')
            ->has('documents', 1)
            ->where('documents.0.id', $mine->id));
});

it('publishes a platform version from the superadmin panel', function () {
    $this->actingAs(superAdmin())
        ->post(superUrl('/legal'), legalPayload(['type' => 'terms']))
        ->assertRedirect();

    expect(LegalDocument::query()->sole()->tenant_id)->toBeNull();
});

it('keeps the superadmin legal panel away from a tenant admin', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(legalAdmin($tenant))
        ->get(superUrl('/legal'))
        ->assertForbidden();
});
