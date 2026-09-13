<?php

use App\Enums\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\TenantLanding;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The calm landing template (SLO-238, docs/25)
|--------------------------------------------------------------------------
|
| A tenant can choose a landing template instead of the plain catalogue page.
| The template's labels are UI text; everything else on it — tagline, FAQ,
| quotes, the practitioner's bio — is the tenant's own content, stored in
| `tenants.landing` and shown as written.
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

/** @return array<string, mixed> */
function calmLandingContent(array $overrides = []): array
{
    return array_merge([
        'template' => 'calm',
        'brand_title' => 'Csendkert',
        'brand_subtitle' => 'Coaching',
        'tagline' => 'Lassan, figyelemmel.',
        'highlights' => [['icon' => 'heart', 'label' => 'Figyelem']],
        'faq' => [['q' => 'Hol vagytok?', 'a' => 'A belvárosban.']],
        'testimonials' => [['name' => 'A. B.', 'text' => 'Nyugodt volt.']],
        'about' => ['name' => 'Kis Petra', 'title' => 'coach', 'bio' => 'Tíz éve.', 'chips' => ['Online']],
    ], $overrides);
}

it('gives a tenant that chose nothing the default page', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Home')
            ->where('landing.template', 'default')
            ->where('landing.faq', []));
});

it('hands the calm template its content and the services their approval flag', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'landing' => calmLandingContent()]);
    Service::factory()->forTenant($tenant)->create(['name' => 'Első alkalom', 'active' => true, 'requires_approval' => true]);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('landing.template', 'calm')
            ->where('landing.brand_title', 'Csendkert')
            ->where('landing.faq.0.a', 'A belvárosban.')
            ->where('landing.testimonials.0.name', 'A. B.')
            ->where('landing.about.name', 'Kis Petra')
            ->where('categories.0.services.0.requires_approval', true));
});

it('never shows one tenant the landing content of another', function () {
    Tenant::factory()->active()->create(['slug' => 'acme', 'landing' => calmLandingContent()]);
    Tenant::factory()->active()->create(['slug' => 'other']);

    $this->get(tenantHost('other'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('landing.template', 'default')
            ->where('landing.brand_title', null)
            ->where('landing.about.name', null));
});

it('⚠️ keeps the landing content when the tenant saves the settings page', function () {
    // The reason the content has its own column: the settings page rebuilds the
    // whole `settings` JSON from the keys it knows, and would have dropped it.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'landing' => calmLandingContent()]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings'), [
            'name' => 'Csendkert Kft.',
            'cancellation_deadline_hours' => 24,
            'slot_interval_minutes' => 30,
            'opening_hours' => 'H–P 9–17',
        ])
        ->assertSessionHasNoErrors();

    expect($tenant->refresh()->landing['brand_title'] ?? null)->toBe('Csendkert')
        ->and($tenant->settings['opening_hours'] ?? null)->toBe('H–P 9–17');
});

it('reads stored content defensively', function () {
    $landing = TenantLanding::fromArray([
        'template' => 'neon',
        'tagline' => ['not', 'a', 'string'],
        'lead' => '   ',
        'highlights' => [
            ['icon' => 'rocket', 'label' => 'Egy'],
            ['label' => 'Kettő'],
            ['icon' => 'heart'],
            ['icon' => 'heart', 'label' => 'Három'],
            ['icon' => 'heart', 'label' => 'Négy'],
        ],
        'bubbles' => ['egy', 42, 'kettő', 'három'],
        'faq' => [['q' => 'Csak kérdés'], 'nem tömb', ['q' => 'K', 'a' => 'V']],
        'about' => 'not an array',
    ]);

    expect($landing->template->value)->toBe('default')
        ->and($landing->tagline)->toBeNull()
        ->and($landing->lead)->toBeNull()
        // Three at most, label required, an unknown icon becomes the leaf.
        ->and($landing->highlights)->toBe([
            ['icon' => 'leaf', 'label' => 'Egy'],
            ['icon' => 'leaf', 'label' => 'Kettő'],
            ['icon' => 'heart', 'label' => 'Három'],
        ])
        ->and($landing->bubbles)->toBe(['egy', 'kettő'])
        ->and($landing->faq)->toBe([['q' => 'K', 'a' => 'V']])
        ->and($landing->aboutName)->toBeNull()
        ->and($landing->usesCalm())->toBeFalse();
});
