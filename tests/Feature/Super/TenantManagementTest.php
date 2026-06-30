<?php

use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Services\Feature\FeatureResolver;
use Database\Seeders\BasePlanSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

it('lists tenants for a super-admin', function () {
    Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Tenants/Index')
            ->has('tenants.data', 1));
});

it('forbids tenant management for a tenant user (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get(superUrl('/tenants'))->assertForbidden();
});

it('redirects a guest to login', function () {
    $this->get(superUrl('/tenants'))->assertRedirectContains('/login');
});

it('filters tenants by search term', function () {
    Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);
    Tenant::factory()->active()->create(['slug' => 'globex', 'name' => 'Globex']);

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants?search=glob'))
        ->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1));
});

it('shows tenant details with resolved features', function () {
    $this->seed(BasePlanSeeder::class);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())
        ->get(superUrl("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Tenants/Show')
            ->where('tenant.slug', 'acme')
            ->has('featureStates', count(Feature::cases())));
});

it('filters tenants by status', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    Tenant::factory()->suspended()->create(['slug' => 'frozen']);

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants?status=suspended'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tenants.data', 1)
            ->where('tenants.data.0.slug', 'frozen'));
});

it('restores and activates a previously archived tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = superAdmin();

    $this->actingAs($admin)->post(superUrl("/tenants/{$tenant->id}/archive"));
    expect(Tenant::withTrashed()->find($tenant->id)->trashed())->toBeTrue();

    $this->actingAs($admin)->post(superUrl("/tenants/{$tenant->id}/activate"));

    $restored = Tenant::withTrashed()->find($tenant->id);
    expect($restored->trashed())->toBeFalse()
        ->and($restored->status)->toBe(TenantStatus::Active);
    $this->get(tenantHost('acme'))->assertOk();
});

it('rejects a slug already taken by another tenant on update', function () {
    Tenant::factory()->active()->create(['slug' => 'taken']);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())->put(superUrl("/tenants/{$tenant->id}"), [
        'name' => 'Acme',
        'slug' => 'taken',
        'timezone' => 'Europe/Budapest',
        'locale' => 'hu',
    ])->assertSessionHasErrors('slug');
});

it('suspends a tenant, taking its surface offline (503)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())
        ->post(superUrl("/tenants/{$tenant->id}/suspend"))
        ->assertRedirect();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
    $this->get(tenantHost('acme'))->assertStatus(503);
});

it('reactivates a suspended tenant', function () {
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())->post(superUrl("/tenants/{$tenant->id}/activate"));

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
    $this->get(tenantHost('acme'))->assertOk();
});

it('archives a tenant (soft delete → 404)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())->post(superUrl("/tenants/{$tenant->id}/archive"));

    expect(Tenant::withTrashed()->find($tenant->id)->trashed())->toBeTrue();
    $this->get(tenantHost('acme'))->assertNotFound();
});

it('extends a trial to 14 days from now', function () {
    $tenant = Tenant::factory()->active()->create(['trial_ends_at' => null]);

    $this->actingAs(superAdmin())->post(superUrl("/tenants/{$tenant->id}/extend-trial"));

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->trial_ends_at->isBetween(now()->addDays(13), now()->addDays(15)))->toBeTrue();
});

it('updates tenant base fields', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Old']);

    $this->actingAs(superAdmin())->put(superUrl("/tenants/{$tenant->id}"), [
        'name' => 'New Name',
        'slug' => 'acme',
        'timezone' => 'Europe/Budapest',
        'locale' => 'en',
    ])->assertRedirect();

    $tenant->refresh();
    expect($tenant->name)->toBe('New Name')->and($tenant->locale)->toBe('en');
});

it('rejects a reserved slug on update', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())->put(superUrl("/tenants/{$tenant->id}"), [
        'name' => 'Acme',
        'slug' => 'admin',
        'timezone' => 'Europe/Budapest',
        'locale' => 'hu',
    ])->assertSessionHasErrors('slug');
});

it('toggles a tenant feature override', function () {
    $this->seed(BasePlanSeeder::class);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    // OnlinePayment is off by default on the base plan; enable it per tenant.
    $this->actingAs(superAdmin())->post(superUrl("/tenants/{$tenant->id}/features"), [
        'feature' => Feature::OnlinePayment->value,
        'enabled' => true,
    ])->assertRedirect();

    expect(TenantFeature::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('feature_code', Feature::OnlinePayment->value)->value('enabled'))->toBeTrue();
    expect(app(FeatureResolver::class)->enabled($tenant->fresh(), Feature::OnlinePayment))->toBeTrue();
});
