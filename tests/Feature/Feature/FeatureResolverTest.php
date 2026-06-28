<?php

use App\Enums\Feature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Services\Feature\FeatureResolver;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;

beforeEach(function () {
    $this->seed(BasePlanSeeder::class);
    $this->resolver = new FeatureResolver;
    $this->tenants = app(TenantManager::class);
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

it('grants a feature enabled by default on the base plan', function () {
    $tenant = Tenant::factory()->create();

    expect($this->resolver->enabled($tenant, Feature::Waitlist))->toBeTrue();
});

it('denies a rate-raising / opt-in feature not granted by the base plan', function () {
    $tenant = Tenant::factory()->create();

    expect($this->resolver->enabled($tenant, Feature::OnlinePayment))->toBeFalse()
        ->and($this->resolver->enabled($tenant, Feature::Invoicing))->toBeFalse();
});

it('lets a tenant override turn a base-default feature off', function () {
    $tenant = Tenant::factory()->create();
    $this->tenants->set($tenant);

    TenantFeature::factory()->create([
        'feature_code' => Feature::Waitlist,
        'enabled' => false,
    ]);

    expect($this->resolver->enabled($tenant, Feature::Waitlist))->toBeFalse();
});

it('lets a tenant override turn an opt-in feature on', function () {
    $tenant = Tenant::factory()->create();
    $this->tenants->set($tenant);

    TenantFeature::factory()->create([
        'feature_code' => Feature::OnlinePayment,
        'enabled' => true,
    ]);

    expect($this->resolver->enabled($tenant, Feature::OnlinePayment))->toBeTrue();
});

it('isolates overrides per tenant — A\'s override does not leak to B', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    TenantFeature::factory()->create([
        'feature_code' => Feature::Waitlist,
        'enabled' => false,
    ]);

    expect($this->resolver->enabled($a, Feature::Waitlist))->toBeFalse()
        ->and($this->resolver->enabled($b, Feature::Waitlist))->toBeTrue();
});

it('lists the enabled feature codes for the frontend', function () {
    $tenant = Tenant::factory()->create();
    $this->tenants->set($tenant);

    TenantFeature::factory()->create([
        'feature_code' => Feature::OnlinePayment,
        'enabled' => true,
    ]);
    TenantFeature::factory()->create([
        'feature_code' => Feature::Waitlist,
        'enabled' => false,
    ]);

    $codes = $this->resolver->enabledCodes($tenant);

    expect($codes)->toContain(Feature::OnlinePayment->value)
        ->and($codes)->toContain(Feature::Reports->value)
        ->and($codes)->not->toContain(Feature::Waitlist->value)
        ->and($codes)->not->toContain(Feature::Sms->value);
});
