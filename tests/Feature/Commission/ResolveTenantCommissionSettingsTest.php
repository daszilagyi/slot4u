<?php

use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantCommissionOverride;
use App\Services\Commission\ResolveTenantCommissionSettings;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->resolver = new ResolveTenantCommissionSettings;
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

it('resolves the platform defaults when the tenant has no override', function () {
    $setting = CommissionSetting::factory()->create(['effective_from' => Carbon::parse('2026-01-01')]);
    $tenant = Tenant::factory()->create();

    $resolved = $this->resolver->resolve($tenant, Carbon::parse('2026-06-15'));

    expect($resolved->freeThresholdMinor)->toBe(1_000_000)
        ->and($resolved->rateBps)->toBe(100)
        ->and($resolved->rateWithIntegrationBps)->toBe(150)
        ->and($resolved->monthlyCapMinor)->toBe(5_000_000)
        ->and($resolved->currency)->toBe('HUF')
        ->and($resolved->settingsId)->toBe($setting->id);
});

it('overlays non-null override fields and inherits the null ones', function () {
    CommissionSetting::factory()->create(['effective_from' => Carbon::parse('2026-01-01')]);
    $tenant = Tenant::factory()->create();
    TenantCommissionOverride::factory()->create([
        'tenant_id' => $tenant->id,
        'rate_bps' => 80,
        'monthly_cap_minor' => 9_000_000,
        // free_threshold_minor + rate_with_integration_bps stay null → inherit
    ]);

    $resolved = $this->resolver->resolve($tenant, Carbon::parse('2026-06-15'));

    expect($resolved->rateBps)->toBe(80)
        ->and($resolved->monthlyCapMinor)->toBe(9_000_000)
        ->and($resolved->freeThresholdMinor)->toBe(1_000_000)
        ->and($resolved->rateWithIntegrationBps)->toBe(150);
});

it('inherits an uncapped (null) platform cap when not overridden', function () {
    CommissionSetting::factory()->create([
        'monthly_cap_minor' => null,
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
    $tenant = Tenant::factory()->create();

    expect($this->resolver->resolve($tenant, Carbon::parse('2026-06-15'))->monthlyCapMinor)->toBeNull();
});

it('pins the effective settings version by effective_from', function () {
    $old = CommissionSetting::factory()->create(['rate_bps' => 100, 'effective_from' => Carbon::parse('2026-01-01')]);
    $new = CommissionSetting::factory()->create(['rate_bps' => 120, 'effective_from' => Carbon::parse('2026-06-01')]);
    $tenant = Tenant::factory()->create();

    expect($this->resolver->resolve($tenant, Carbon::parse('2026-05-31'))->settingsId)->toBe($old->id)
        ->and($this->resolver->resolve($tenant, Carbon::parse('2026-06-15'))->settingsId)->toBe($new->id)
        ->and($this->resolver->resolve($tenant, Carbon::parse('2026-06-15'))->rateBps)->toBe(120);
});

it('throws when no settings are effective at the instant', function () {
    CommissionSetting::factory()->create(['effective_from' => Carbon::parse('2026-06-01')]);
    $tenant = Tenant::factory()->create();

    $this->resolver->resolve($tenant, Carbon::parse('2026-01-01'));
})->throws(RuntimeException::class);

it('exposes the integration-aware rate', function () {
    CommissionSetting::factory()->create([
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
    $tenant = Tenant::factory()->create();

    $resolved = $this->resolver->resolve($tenant, Carbon::parse('2026-06-15'));

    expect($resolved->rateFor(false))->toBe(100)
        ->and($resolved->rateFor(true))->toBe(150);
});

it('resolves a tenant override regardless of the bound tenant context', function () {
    CommissionSetting::factory()->create(['effective_from' => Carbon::parse('2026-01-01')]);
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    TenantCommissionOverride::factory()->create(['tenant_id' => $a->id, 'rate_bps' => 70]);

    // Bind a different tenant; the resolver must still find A's override.
    app(TenantManager::class)->set($b);

    expect($this->resolver->resolve($a, Carbon::parse('2026-06-15'))->rateBps)->toBe(70);
});
