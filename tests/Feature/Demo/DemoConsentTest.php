<?php

use App\Enums\ConsentContext;
use App\Enums\Role;
use App\Http\Controllers\Tenant\DemoLoginController;
use App\Models\LegalConsent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Legal\LegalDocumentRegistry;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\Demo\SmokeDemoPersona;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The demo's legal consent (SLO-209)
|--------------------------------------------------------------------------
|
| A seeded demo account used to stand on day zero: it had accepted nothing, so
| EnsureLegalConsent (SLO-161) held it at the re-acceptance screen. The visitor
| the landing page promised a dashboard to got a form listing two 0.1-draft
| documents instead — and, because the gate is on every authenticated request,
| the tenant's PUBLIC booking page too, for as long as that session lived.
|
| The fix is in the seed, not in the gate. That distinction is what the last
| test here defends: exempting `is_demo` from EnsureLegalConsent would have been
| one line and would have traded a real legal control for a sales demo. So there
| is a test that a REAL tenant's staff are still stopped, and it must keep
| failing to be exempted.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    // Without documents in force there is nothing to accept and nothing to be
    // stopped by — the whole subject of this file would be vacuous.
    $this->seed(LegalDocumentSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
    Carbon::setTestNow();
});

/** The smoke persona, seeded — the framework, without four businesses of content. */
function seededDemoTenant(): Tenant
{
    test()->artisan('demo:seed', ['--tenant' => (new SmokeDemoPersona)->slug()])->assertSuccessful();

    return Tenant::withoutGlobalScopes()->where('slug', (new SmokeDemoPersona)->slug())->firstOrFail();
}

function tenantUrl(Tenant $tenant, string $path): string
{
    return 'http://'.$tenant->slug.'.'.config('tenancy.central_domain').$path;
}

it('leaves no seeded demo account with a document still to accept', function () {
    $tenant = seededDemoTenant();

    $registry = app(LegalDocumentRegistry::class);
    $users = User::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->get();

    expect($users)->not->toBeEmpty();

    foreach ($users as $user) {
        expect($registry->outstandingFor($user))
            ->toBeEmpty("{$user->email} still owes a document");
    }
});

it('records the acceptance as evidence, against the demo tenant', function () {
    $tenant = seededDemoTenant();

    $consents = LegalConsent::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->get();

    // Two platform documents, and the row has to say who accepted what and in
    // what circumstances — a consent record that only proves "somebody agreed"
    // is not evidence of anything (docs/19 §3).
    expect($consents)->toHaveCount(2 * User::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count());

    foreach ($consents as $consent) {
        expect($consent->user_id)->not->toBeNull()
            ->and($consent->context)->toBe(ConsentContext::Reconsent);
    }
});

it('⚠️ lands the one-click visitor on the dashboard, not on the consent wall', function () {
    // The test that would have caught SLO-209. The sign-in itself always
    // succeeded; the wall came up on the request after it, which is why
    // asserting the redirect target of /demo/login was not enough.
    $tenant = seededDemoTenant();

    $url = URL::temporarySignedRoute(
        'tenant.demo.login',
        Carbon::now()->addMinutes(DemoLoginController::LIFETIME_MINUTES),
        ['tenant' => $tenant->slug],
    );

    $this->get($url)->assertRedirect('/dashboard');

    $this->get(tenantUrl($tenant, '/dashboard'))
        ->assertOk()
        ->assertDontSee('/consent');
});

it('⚠️ leaves the public demo page reachable while that session is open', function () {
    // The half of the bug that was easy to miss: the gate runs on every
    // authenticated request, so a visitor who had looked at the admin view could
    // no longer see the booking page the landing iframe shows.
    $tenant = seededDemoTenant();

    $this->get(URL::temporarySignedRoute(
        'tenant.demo.login',
        Carbon::now()->addMinutes(DemoLoginController::LIFETIME_MINUTES),
        ['tenant' => $tenant->slug],
    ));

    $this->get(tenantUrl($tenant, '/'))->assertOk();
});

it('⚠️ still stops a real tenant’s staff at the wall — the gate is not weakened', function () {
    // The counterweight. If someone ever "fixes" this by exempting demo tenants
    // in the middleware, the exemption will not be far from making this pass by
    // accident; if it is ever widened to everyone, this fails immediately.
    $tenant = Tenant::factory()->active()->create(['slug' => 'valodi-ceg']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->getKey()]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    expect($tenant->refresh()->is_demo)->toBeFalse();

    $this->actingAs($user)
        ->get(tenantUrl($tenant, '/dashboard'))
        ->assertRedirect('/consent');
});
