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
use Inertia\Testing\AssertableInertia;
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

/*
| The brochure stays readable (SLO-219)
|
| slot4u.hu's landing is public: a stranger reads it without an account. So
| signing in must not take it away — and it did, because this gate ran on every
| authenticated request. On the morning a new ÁSZF version is published that
| would be every customer at once, each of them meeting a legal form where they
| expected the home page.
|
| The exemption is narrow and it is paired: these pages are reachable, AND they
| render as if nobody were signed in, so nothing personal reaches a visitor whose
| consent has lapsed. Both halves are asserted below, and so is the thing that
| must NOT have changed.
*/

it('⚠️ lets a signed-in visitor read the marketing pages with a document outstanding', function () {
    $tenant = gateTenant();
    LegalDocument::factory()->platform()->terms()->create();

    $central = 'http://'.config('tenancy.central_domain');

    $this->actingAs(gateStaff($tenant))
        ->get($central)
        ->assertSuccessful();
});

it('⚠️ renders those pages as a brochure — the session never reaches them', function () {
    // Nulled server-side, not hidden in the layout. An unrendered name that is
    // still sitting in the Inertia prop payload has still been sent to someone
    // whose consent has lapsed, and the payload is readable in view-source.
    $tenant = gateTenant();
    LegalDocument::factory()->platform()->terms()->create();

    $this->actingAs(gateStaff($tenant))
        ->get('http://'.config('tenancy.central_domain'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));
});

it('gives the session back on those same pages once the document is accepted', function () {
    // The exemption is about outstanding consent, not about the marketing pages
    // being permanently anonymous — a customer in good standing still gets their
    // header (SLO-215).
    $tenant = gateTenant();
    $document = LegalDocument::factory()->platform()->terms()->create();
    $user = gateStaff($tenant);
    LegalConsent::factory()->forTenant($tenant)->forDocument($document)->byUser($user)->create();

    $this->actingAs($user)
        ->get('http://'.config('tenancy.central_domain'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.id', $user->getKey()));
});

it('⚠️ still holds the product surfaces, which is the half that must not move', function () {
    // The counterweight. An exemption written slightly too wide would let the
    // booking system and the admin panel through as well, and every assertion
    // above would still pass — this is the one that would not.
    $tenant = gateTenant();
    LegalDocument::factory()->platform()->terms()->create();
    $user = gateStaff($tenant);

    $this->actingAs($user)->get(tenantHost('acme', '/dashboard'))->assertRedirect('/consent');
    $this->actingAs($user)->get(tenantHost('acme'))->assertRedirect('/consent');
    $this->actingAs($user)->get('http://'.config('tenancy.central_domain').'/register')->assertRedirect('/consent');
});
