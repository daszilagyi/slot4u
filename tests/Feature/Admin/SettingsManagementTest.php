<?php

use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A tenant-admin user in the given tenant (has settings.edit). */
function settingsAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);

    return $user;
}

/** Enable feature_branding for the tenant via a tenant_features override. */
function enableBranding(Tenant $tenant): void
{
    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::Branding, 'enabled' => true]);
    app(TenantManager::class)->forget();
}

/** Minimal valid settings payload (the required booking rules). */
function settingsPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Acme Kft.',
        'cancellation_deadline_hours' => 24,
        'slot_interval_minutes' => 30,
    ], $overrides);
}

it('renders the settings page with branding locked by default', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/Index')
            ->where('brandingEnabled', false)
            ->where('settings.slot_interval_minutes', 30)
            ->has('branding'));
});

it('updates the company profile and booking rules', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), settingsPayload([
            'name' => 'Acme Wellness',
            'description' => 'A legjobb szalon',
            'email' => 'hello@acme.test',
            'social' => ['website' => 'https://acme.test'],
            'cancellation_deadline_hours' => 48,
            'slot_interval_minutes' => 15,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $tenant->refresh();
    expect($tenant->name)->toBe('Acme Wellness')
        ->and($tenant->settings['description'])->toBe('A legjobb szalon')
        ->and($tenant->settings['email'])->toBe('hello@acme.test')
        ->and($tenant->settings['social']['website'])->toBe('https://acme.test')
        ->and($tenant->settings['cancellation_deadline_hours'])->toBe(48)
        ->and($tenant->settings['slot_interval_minutes'])->toBe(15);
});

it('rejects an invalid slot interval', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), settingsPayload(['slot_interval_minutes' => 20]))
        ->assertSessionHasErrors('slot_interval_minutes');
});

it('rejects a name that is missing', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), settingsPayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

it('rejects branding input when the feature is disabled', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);

    // feature_branding is off by default on the base plan.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), settingsPayload(['primary_color' => '#ff0000']))
        ->assertSessionHasErrors('primary_color');

    expect($tenant->fresh()->branding)->toBeNull();
});

it('saves the primary colour when branding is enabled', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);
    enableBranding($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), settingsPayload(['primary_color' => '#112233']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($tenant->fresh()->branding['primary_color'])->toBe('#112233');
});

it('uploads a logo to the tenant storage prefix when branding is enabled', function () {
    Storage::fake('public');
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);
    enableBranding($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), settingsPayload([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $path = $tenant->fresh()->branding['logo_path'];
    expect($path)->toStartWith("tenants/{$tenant->id}/");
    Storage::disk('public')->assertExists($path);
});

it('removes an existing logo when remove_logo is set', function () {
    Storage::fake('public');
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);
    enableBranding($tenant);
    $path = UploadedFile::fake()->image('old.png')->store("tenants/{$tenant->id}", 'public');
    $tenant->update(['branding' => ['primary_color' => '#6366f1', 'logo_path' => $path]]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), settingsPayload(['remove_logo' => true]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($tenant->fresh()->branding['logo_path'])->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('rejects a non-image logo upload', function () {
    Storage::fake('public');
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = settingsAdmin($tenant);
    enableBranding($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), settingsPayload([
            'logo' => UploadedFile::fake()->create('malware.pdf', 100, 'application/pdf'),
        ]))
        ->assertSessionHasErrors('logo');
});

it('forbids a manager without settings.edit (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $manager = User::factory()->create(['tenant_id' => $tenant->id]);
    $manager->assignRole(Role::Manager->value);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/settings'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/settings'))->assertRedirectContains('/login');
});
