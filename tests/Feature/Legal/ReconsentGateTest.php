<?php

use App\Enums\ConsentContext;
use App\Enums\Role;
use App\Models\LegalConsent;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Legal\LegalDocumentRegistry;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Re-acceptance when a version changes (SLO-161)
|--------------------------------------------------------------------------
|
| This is what makes the versioning mean anything. Without the gate a new text
| would apply to nobody who already had an account, and "the current terms"
| would be a label on a page rather than something people agreed to.
|
| The other half of the risk is the gate becoming a trap: someone who must accept
| a document has to be able to read it, submit the acceptance, and log out.
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

/** A tenant admin of `acme`, in the tenant's own permission team. */
function gateStaff(Tenant $tenant): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

function gateTenant(): Tenant
{
    return Tenant::factory()->active()->create(['slug' => 'acme']);
}

it('lets a staff member through when nothing has been published', function () {
    $tenant = gateTenant();

    $this->actingAs(gateStaff($tenant))
        ->get(tenantHost('acme', '/dashboard'))
        ->assertSuccessful();
});

it('holds a staff member at the door when a platform version is outstanding', function () {
    $tenant = gateTenant();
    LegalDocument::factory()->platform()->terms()->create();

    $this->actingAs(gateStaff($tenant))
        ->get(tenantHost('acme', '/dashboard'))
        ->assertRedirect('/consent');
});

it('lets them through once they have accepted that version', function () {
    $tenant = gateTenant();
    $document = LegalDocument::factory()->platform()->terms()->create();
    $user = gateStaff($tenant);

    LegalConsent::factory()->forTenant($tenant)->forDocument($document)->byUser($user)->create();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertSuccessful();
});

it('holds them again when a NEW version comes into force', function () {
    // The whole point. An acceptance of 1.0 is not an acceptance of 2.0, and the
    // old record stays exactly where it is.
    $tenant = gateTenant();
    $old = LegalDocument::factory()->platform()->terms()->version('1.0')
        ->effectiveAt(now()->subMonth())->create();
    $user = gateStaff($tenant);
    LegalConsent::factory()->forTenant($tenant)->forDocument($old)->byUser($user)->create();

    LegalDocument::factory()->platform()->terms()->version('2.0')
        ->effectiveAt(now()->subMinute())->create();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertRedirect('/consent');

    expect(LegalConsent::withoutGlobalScopes()->where('legal_document_id', $old->id)->exists())
        ->toBeTrue();
});

it('does not ask a super-admin to accept the platform terms', function () {
    // slot4u's own staff are not slot4u's customers; asking would be the
    // platform contracting with itself.
    LegalDocument::factory()->platform()->terms()->create();

    $this->actingAs(superAdmin())
        ->get(superUrl('/'))
        ->assertSuccessful();
});

it('leaves the public booking page reachable for a signed-out visitor', function () {
    gateTenant();
    LegalDocument::factory()->platform()->terms()->create();

    $this->get(tenantHost('acme', '/book'))->assertSuccessful();
});

it('keeps the document itself readable while the gate is closed', function () {
    // Otherwise the gate is a trap: nobody can accept a text they are redirected
    // away from.
    $tenant = gateTenant();
    $document = LegalDocument::factory()->platform()->terms()->create();

    $this->actingAs(gateStaff($tenant))
        ->get(tenantHost('acme', '/legal/'.$document->id))
        ->assertSuccessful();
});

it('keeps logout reachable while the gate is closed', function () {
    $tenant = gateTenant();
    LegalDocument::factory()->platform()->terms()->create();

    $this->actingAs(gateStaff($tenant))
        ->post(tenantHost('acme', '/logout'))
        ->assertRedirect();

    $this->assertGuest();
});

it('records the acceptance the blocking screen submits, and opens the door', function () {
    $tenant = gateTenant();
    $document = LegalDocument::factory()->platform()->terms()->create();
    $user = gateStaff($tenant);

    $this->actingAs($user)
        ->post(tenantHost('acme', '/consent'), ['accepted_legal' => true])
        ->assertRedirect('/');

    $consent = LegalConsent::withoutGlobalScopes()->sole();

    expect($consent->legal_document_id)->toBe($document->id)
        ->and($consent->user_id)->toBe($user->id)
        ->and($consent->context)->toBe(ConsentContext::Reconsent);

    $this->actingAs($user)->get(tenantHost('acme', '/dashboard'))->assertSuccessful();
});

it('refuses the blocking screen without a tick, and records nothing', function () {
    $tenant = gateTenant();
    LegalDocument::factory()->platform()->terms()->create();

    $this->actingAs(gateStaff($tenant))
        ->from(tenantHost('acme', '/consent'))
        ->post(tenantHost('acme', '/consent'), [])
        ->assertSessionHasErrors('accepted_legal');

    expect(LegalConsent::withoutGlobalScopes()->count())->toBe(0);
});

it('asks a customer for the tenant documents, not the platform ones', function () {
    // docs/19 §1: the tenant is the controller of its customers' data, and
    // slot4u is not a party to that relationship at all.
    $tenant = gateTenant();
    LegalDocument::factory()->platform()->terms()->create();
    $tenantDocument = LegalDocument::factory()->forTenant($tenant)->privacy()->create();

    $customer = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $customer->assignRole(Role::Customer->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $outstanding = app(LegalDocumentRegistry::class)->outstandingFor($customer);

    expect($outstanding->pluck('id')->all())->toBe([$tenantDocument->id]);
});
