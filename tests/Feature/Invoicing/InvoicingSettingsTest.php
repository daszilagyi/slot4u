<?php

use App\Actions\Tenant\SetTenantFeature;
use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicing\Billingo\BillingoClient;
use App\Settings\TenantInvoicingSettings;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The invoicing settings screen (SLO-167)
|--------------------------------------------------------------------------
|
| This screen holds a live provider credential, so most of what is tested here is
| about the key NOT going anywhere: not to the browser, not out of storage in the
| clear, and not out of existence because somebody edited an address.
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

function invoicingTenant(array $invoicing = []): Tenant
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'invoicing' => $invoicing === [] ? null : $invoicing,
    ]);

    app(SetTenantFeature::class)($tenant, Feature::Invoicing, true);

    return $tenant;
}

function invoicingAdmin(Tenant $tenant): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

it('never sends the stored API key to the browser', function () {
    // The screen learns that a key exists, never what it is. A prop carrying a
    // live credential would travel into every page cache and error report that
    // touches the response.
    $tenant = invoicingTenant(['provider' => 'billingo', 'api_key' => 'super-secret-key', 'block_id' => 1]);
    Http::fake([BillingoClient::BASE_URL.'/*' => Http::response(['data' => []], 200)]);

    $response = $this->actingAs(invoicingAdmin($tenant))
        ->get(tenantHost('acme', '/settings/invoicing'));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Invoicing')
            ->where('settings.hasApiKey', true)
            ->missing('settings.apiKey'));

    expect($response->content())->not->toContain('super-secret-key');
});

it('keeps the stored key when the field is left blank', function () {
    // The form cannot show the key, so it cannot send it back — treating a blank
    // as a deletion would wipe the credential every time somebody edited the
    // seller's address.
    $tenant = invoicingTenant(['provider' => 'billingo', 'api_key' => 'keep-me', 'block_id' => 7]);
    Http::fake([BillingoClient::BASE_URL.'/*' => Http::response(['data' => []], 200)]);

    $this->actingAs(invoicingAdmin($tenant))
        ->post(tenantHost('acme', '/settings/invoicing'), [
            'provider' => 'billingo',
            'api_key' => '',
            'seller_name' => 'Acme Kft.',
            'block_id' => 7,
        ])->assertRedirect();

    expect(TenantInvoicingSettings::fromArray($tenant->fresh()->invoicing)->apiKey)->toBe('keep-me');
});

it('replaces the key when a new one is given', function () {
    $tenant = invoicingTenant(['provider' => 'billingo', 'api_key' => 'old', 'block_id' => 7]);
    Http::fake([BillingoClient::BASE_URL.'/*' => Http::response(['data' => []], 200)]);

    $this->actingAs(invoicingAdmin($tenant))
        ->post(tenantHost('acme', '/settings/invoicing'), [
            'provider' => 'billingo',
            'api_key' => 'new',
            'block_id' => 7,
        ])->assertRedirect();

    expect(TenantInvoicingSettings::fromArray($tenant->fresh()->invoicing)->apiKey)->toBe('new');
});

it('refuses Billingo without a document block', function () {
    // Otherwise the tenant looks configured and fails on its first real invoice —
    // by which time the customer has already paid.
    $tenant = invoicingTenant();
    Http::fake();

    $this->actingAs(invoicingAdmin($tenant))
        ->from(tenantHost('acme', '/settings/invoicing'))
        ->post(tenantHost('acme', '/settings/invoicing'), [
            'provider' => 'billingo',
            'api_key' => 'k',
        ])->assertSessionHasErrors('block_id');
});

it('does not offer a provider that has no adapter', function () {
    $tenant = invoicingTenant();
    Http::fake();

    $this->actingAs(invoicingAdmin($tenant))
        ->get(tenantHost('acme', '/settings/invoicing'))
        ->assertInertia(fn ($page) => $page
            ->has('providers', 1)
            ->where('providers.0.value', 'billingo'));
});

it('refuses a provider that has no adapter even if the form is forged', function () {
    $tenant = invoicingTenant();
    Http::fake();

    $this->actingAs(invoicingAdmin($tenant))
        ->from(tenantHost('acme', '/settings/invoicing'))
        ->post(tenantHost('acme', '/settings/invoicing'), [
            'provider' => 'szamlazzhu',
            'api_key' => 'k',
        ])->assertSessionHasErrors('provider');
});

it('offers the blocks and accounts from the tenant own provider account', function () {
    // Not free-text ids: an admin should not have to go and look a number up,
    // and a wrong one would only surface on a real invoice.
    $tenant = invoicingTenant(['provider' => 'billingo', 'api_key' => 'k', 'block_id' => 329303]);

    Http::fake([
        BillingoClient::BASE_URL.'/document-blocks' => Http::response(['data' => [
            ['id' => 329303, 'name' => 'Számlák', 'type' => 'invoice'],
        ]], 200),
        BillingoClient::BASE_URL.'/bank-accounts' => Http::response(['data' => [
            ['id' => 55, 'name' => 'OTP', 'account_number' => '11111111-22222222'],
        ]], 200),
    ]);

    $this->actingAs(invoicingAdmin($tenant))
        ->get(tenantHost('acme', '/settings/invoicing'))
        ->assertInertia(fn ($page) => $page
            ->where('blocks.0.id', 329303)
            ->where('bankAccounts.0.id', 55)
            ->where('providerError', null));
});

it('reports a refusing provider as a message rather than failing the page', function () {
    // A wrong or revoked key is the most likely reason to be on this screen; a
    // 500 would tell the admin nothing about which field to fix.
    $tenant = invoicingTenant(['provider' => 'billingo', 'api_key' => 'revoked']);

    Http::fake([BillingoClient::BASE_URL.'/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->actingAs(invoicingAdmin($tenant))
        ->get(tenantHost('acme', '/settings/invoicing'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('blocks', [])
            ->where('providerError', fn ($error) => str_contains((string) $error, 'Unauthenticated')));
});

it('asks the provider for nothing until a key is stored', function () {
    $tenant = invoicingTenant();
    Http::fake();

    $this->actingAs(invoicingAdmin($tenant))
        ->get(tenantHost('acme', '/settings/invoicing'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('settings.complete', false));

    // Narrowed to Billingo on purpose: Inertia's SSR render is itself an HTTP
    // call, so a blanket assertNothingSent() would fail for the wrong reason.
    $billingo = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'billingo'))
        ->count();

    expect($billingo)->toBe(0);
});

it('keeps the screen away from a tenant without the invoicing feature', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    Http::fake();

    $this->actingAs(invoicingAdmin($tenant))
        ->get(tenantHost('acme', '/settings/invoicing'))
        ->assertForbidden();
});

it('keeps the screen away from staff without settings.edit', function () {
    $tenant = invoicingTenant();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user->assignRole(Role::Employee->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    Http::fake();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/settings/invoicing'))
        ->assertForbidden();
});

it('stores the credential encrypted at rest', function () {
    $tenant = invoicingTenant();
    Http::fake();

    $this->actingAs(invoicingAdmin($tenant))
        ->post(tenantHost('acme', '/settings/invoicing'), [
            'provider' => 'billingo',
            'api_key' => 'plaintext-would-be-a-bug',
            'block_id' => 7,
        ])->assertRedirect();

    $raw = (string) DB::table('tenants')
        ->where('id', $tenant->id)->value('invoicing');

    expect($raw)->not->toContain('plaintext-would-be-a-bug');
});
