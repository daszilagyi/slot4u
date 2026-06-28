<?php

use App\Enums\Feature as FeatureEnum;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->seed(BasePlanSeeder::class);
    $this->tenants = app(TenantManager::class);
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

it('resolves Pennant flags against the explicit tenant scope', function () {
    $tenant = Tenant::factory()->create();

    expect(Feature::for($tenant)->active(FeatureEnum::Waitlist->value))->toBeTrue()
        ->and(Feature::for($tenant)->active(FeatureEnum::OnlinePayment->value))->toBeFalse();
});

it('uses the current tenant as the default scope', function () {
    $tenant = Tenant::factory()->create();
    $this->tenants->set($tenant);

    expect(Feature::active(FeatureEnum::Reports->value))->toBeTrue();
});

it('reflects a tenant override through Pennant', function () {
    $tenant = Tenant::factory()->create();
    $this->tenants->set($tenant);

    TenantFeature::factory()->create([
        'feature_code' => FeatureEnum::OnlinePayment,
        'enabled' => true,
    ]);

    expect(Feature::for($tenant)->active(FeatureEnum::OnlinePayment->value))->toBeTrue();
});

it('resolves every flag to off outside tenant context', function () {
    // No tenant bound: console/admin/queue context.
    expect(Feature::active(FeatureEnum::Waitlist->value))->toBeFalse();
});
