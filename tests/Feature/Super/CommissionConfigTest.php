<?php

use App\Actions\Commission\SetTenantCommissionOverride;
use App\Models\AuditLog;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantCommissionOverride;
use App\Models\User;
use App\Services\Commission\ResolveTenantCommissionSettings;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

/** The newest audit entry for an action code, or null if it was never recorded. */
function auditLogFor(string $action): ?AuditLog
{
    return AuditLog::query()->where('action', $action)->latest('id')->first();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function versionPayload(array $overrides = []): array
{
    return array_merge([
        'free_threshold_minor' => 1_000_000,
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'monthly_cap_minor' => 5_000_000,
        'currency' => 'HUF',
        'effective_from' => '2026-08-01T00:00:00+00:00',
    ], $overrides);
}

// --- Access control ---

it('renders the commission config for a superadmin', function () {
    CommissionSetting::factory()->create(['effective_from' => now()->subMonth()]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/commission'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Commission/Index')
            ->has('versions', 1));
});

it('denies a tenant user', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get(superUrl('/commission'))->assertForbidden();
});

// Separate test: actingAs() binds the user for the rest of the test, so a guest
// request has to start from a clean one.
it('sends a guest to the login page', function () {
    $this->get(superUrl('/commission'))->assertRedirectContains('/login');
    $this->post(superUrl('/commission'), versionPayload())->assertRedirectContains('/login');
});

it('does not let a tenant user publish a version or touch an override', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->post(superUrl('/commission'), versionPayload())->assertForbidden();
    $this->actingAs($user)->put(superUrl("/tenants/{$tenant->id}/commission-override"), ['rate_bps' => 50])->assertForbidden();
    $this->actingAs($user)->delete(superUrl("/tenants/{$tenant->id}/commission-override"))->assertForbidden();

    expect(CommissionSetting::query()->count())->toBe(0)
        ->and(TenantCommissionOverride::query()->withoutGlobalScopes()->count())->toBe(0);
});

// --- Versions ---

it('publishes a new version with the author, leaving earlier ones untouched', function () {
    $existing = CommissionSetting::factory()->create([
        'rate_bps' => 100,
        'effective_from' => now()->subMonth(),
    ]);
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(superUrl('/commission'), versionPayload(['rate_bps' => 120]))
        ->assertRedirect();

    $published = CommissionSetting::query()->latest('id')->first();

    expect(CommissionSetting::query()->count())->toBe(2)
        ->and($published->rate_bps)->toBe(120)
        ->and($published->created_by)->toBe($admin->id)
        // Versions are immutable: publishing never rewrites the superseded row.
        ->and($existing->fresh()->rate_bps)->toBe(100);
});

it('audits the published version', function () {
    $admin = superAdmin();

    $this->actingAs($admin)->post(superUrl('/commission'), versionPayload(['rate_bps' => 120]));

    $log = auditLogFor('commission.settings_created');
    $setting = CommissionSetting::query()->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        // Platform-wide pricing: not attributed to any single tenant.
        ->and($log->tenant_id)->toBeNull()
        ->and($log->auditable_id)->toBe($setting->id)
        ->and($log->new_values['rate_bps'])->toBe(120);
});

it('marks the effective version and flags a scheduled one', function () {
    Carbon::setTestNow('2026-07-16 12:00:00');

    $superseded = CommissionSetting::factory()->create(['effective_from' => now()->subMonths(2)]);
    $effective = CommissionSetting::factory()->create(['effective_from' => now()->subDay()]);
    $scheduled = CommissionSetting::factory()->create(['effective_from' => now()->addMonth()]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/commission'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('effective_id', $effective->id)
            // Newest first, matching the resolver's ordering.
            ->where('versions.0.id', $scheduled->id)
            ->where('versions.0.scheduled', true)
            ->where('versions.1.id', $effective->id)
            ->where('versions.1.scheduled', false)
            ->where('versions.2.id', $superseded->id));
});

it('reports no effective version while every version is still scheduled', function () {
    CommissionSetting::factory()->create(['effective_from' => now()->addWeek()]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/commission'))
        ->assertInertia(fn (Assert $page) => $page->where('effective_id', null));
});

it('rejects an integration rate below the base rate', function () {
    $this->actingAs(superAdmin())
        ->post(superUrl('/commission'), versionPayload([
            'rate_bps' => 150,
            'rate_with_integration_bps' => 100,
        ]))
        ->assertSessionHasErrors('rate_with_integration_bps');

    expect(CommissionSetting::query()->count())->toBe(0);
});

it('rejects a non-integer amount and a foreign currency', function () {
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(superUrl('/commission'), versionPayload(['free_threshold_minor' => '10000.5']))
        ->assertSessionHasErrors('free_threshold_minor');

    $this->actingAs($admin)
        ->post(superUrl('/commission'), versionPayload(['currency' => 'EUR']))
        ->assertSessionHasErrors('currency');
});

it('accepts a null cap as uncapped', function () {
    $this->actingAs(superAdmin())
        ->post(superUrl('/commission'), versionPayload(['monthly_cap_minor' => null]))
        ->assertRedirect();

    expect(CommissionSetting::query()->latest('id')->first()->monthly_cap_minor)->toBeNull();
});

// --- Tenant override ---

it('shows the tenant override and the resolved effective values', function () {
    CommissionSetting::factory()->create([
        'free_threshold_minor' => 1_000_000,
        'rate_bps' => 100,
        'effective_from' => now()->subMonth(),
    ]);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    TenantCommissionOverride::factory()->create([
        'tenant_id' => $tenant->id,
        'rate_bps' => 50,
        'free_threshold_minor' => null,
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('commissionOverride.rate_bps', 50)
            // NULL override field = inherit, so the effective threshold is the
            // platform one while the rate is the tenant's.
            ->where('commissionOverride.free_threshold_minor', null)
            ->where('commissionEffective.rate_bps', 50)
            ->where('commissionEffective.free_threshold_minor', 1_000_000));
});

it('reports no effective values while no version is configured', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())
        ->get(superUrl("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('commissionEffective', null)
            ->where('commissionOverride', null));
});

it('writes an override for a tenant it has no ambient context for', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = superAdmin();

    $this->actingAs($admin)
        ->put(superUrl("/tenants/{$tenant->id}/commission-override"), [
            'rate_bps' => 50,
            'monthly_cap_minor' => 2_000_000,
            'note' => 'Korai partner',
        ])
        ->assertRedirect();

    $override = TenantCommissionOverride::query()->withoutGlobalScopes()->whereKey($tenant->id)->first();

    expect($override)->not->toBeNull()
        // tenant_id is not fillable and no tenant is bound in the superadmin
        // panel — the action must still key the row correctly.
        ->and($override->tenant_id)->toBe($tenant->id)
        ->and($override->rate_bps)->toBe(50)
        ->and($override->monthly_cap_minor)->toBe(2_000_000)
        ->and($override->note)->toBe('Korai partner')
        ->and($override->overridden_by)->toBe($admin->id)
        // Omitted fields inherit rather than reading as zero.
        ->and($override->free_threshold_minor)->toBeNull();
});

it('updates an existing override in place and blanks a cleared field', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    TenantCommissionOverride::factory()->create([
        'tenant_id' => $tenant->id,
        'rate_bps' => 50,
        'free_threshold_minor' => 500_000,
    ]);

    $this->actingAs(superAdmin())
        ->put(superUrl("/tenants/{$tenant->id}/commission-override"), ['rate_bps' => 75]);

    $overrides = TenantCommissionOverride::query()->withoutGlobalScopes()->get();

    expect($overrides)->toHaveCount(1)
        ->and($overrides->first()->rate_bps)->toBe(75)
        // Submitting without the threshold means "inherit it again".
        ->and($overrides->first()->free_threshold_minor)->toBeNull();
});

it('audits the override write with its old and new values', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    TenantCommissionOverride::factory()->create(['tenant_id' => $tenant->id, 'rate_bps' => 50]);
    $admin = superAdmin();

    $this->actingAs($admin)
        ->put(superUrl("/tenants/{$tenant->id}/commission-override"), ['rate_bps' => 75]);

    $log = auditLogFor('commission.override_updated');

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->tenant_id)->toBe($tenant->id)
        ->and($log->old_values['rate_bps'])->toBe(50)
        ->and($log->new_values['rate_bps'])->toBe(75);
});

it('clears an override back to the platform settings, audited', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    TenantCommissionOverride::factory()->create(['tenant_id' => $tenant->id, 'rate_bps' => 50]);

    $this->actingAs(superAdmin())
        ->delete(superUrl("/tenants/{$tenant->id}/commission-override"))
        ->assertRedirect();

    $log = auditLogFor('commission.override_cleared');

    expect(TenantCommissionOverride::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($log)->not->toBeNull()
        ->and($log->tenant_id)->toBe($tenant->id)
        ->and($log->old_values['rate_bps'])->toBe(50);
});

it('records nothing when clearing a tenant that has no override', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())
        ->delete(superUrl("/tenants/{$tenant->id}/commission-override"))
        ->assertRedirect();

    expect(auditLogFor('commission.override_cleared'))->toBeNull();
});

it('never touches another tenant override', function () {
    $acme = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    TenantCommissionOverride::factory()->create(['tenant_id' => $other->id, 'rate_bps' => 50]);

    $this->actingAs(superAdmin())
        ->put(superUrl("/tenants/{$acme->id}/commission-override"), ['rate_bps' => 75]);

    $others = TenantCommissionOverride::query()->withoutGlobalScopes()->whereKey($other->id)->first();

    expect($others->rate_bps)->toBe(50)
        ->and(TenantCommissionOverride::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('writes the override onto the named tenant even when another tenant is bound', function () {
    $target = Tenant::factory()->active()->create(['slug' => 'target']);
    $bound = Tenant::factory()->active()->create(['slug' => 'bound']);
    $admin = superAdmin();
    $this->actingAs($admin);

    // The superadmin panel binds no tenant, but the BelongsToTenant creating
    // hook stamps the bound one over any caller-supplied tenant_id — so a
    // tenant-bound caller (a future API or console entry point) must not be able
    // to redirect this write onto the wrong tenant's bill.
    app(TenantManager::class)->set($bound);

    try {
        app(SetTenantCommissionOverride::class)->set($target, ['rate_bps' => 42]);
    } finally {
        app(TenantManager::class)->forget();
    }

    $overrides = TenantCommissionOverride::query()->withoutGlobalScopes()->get();

    expect($overrides)->toHaveCount(1)
        ->and($overrides->first()->tenant_id)->toBe($target->id)
        ->and($overrides->first()->rate_bps)->toBe(42);
});

// --- The override is what the resolver actually reads ---

it('feeds the resolver: a published version and override price the tenant', function () {
    Carbon::setTestNow('2026-07-16 12:00:00');

    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = superAdmin();

    $this->actingAs($admin)->post(superUrl('/commission'), versionPayload([
        'free_threshold_minor' => 1_000_000,
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'effective_from' => now()->subDay()->toIso8601String(),
    ]));
    $this->actingAs($admin)->put(superUrl("/tenants/{$tenant->id}/commission-override"), ['rate_bps' => 60]);

    $resolved = app(ResolveTenantCommissionSettings::class)->resolve($tenant, now());

    expect($resolved->rateBps)->toBe(60)
        ->and($resolved->freeThresholdMinor)->toBe(1_000_000)
        ->and($resolved->rateWithIntegrationBps)->toBe(150);
});
