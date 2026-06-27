<?php

use App\Enums\Feature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Tenancy\TenantManager;

beforeEach(function () {
    $this->tenants = app(TenantManager::class);
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

it('auto-fills tenant_id from the current tenant on create', function () {
    $tenant = Tenant::factory()->create();
    $this->tenants->set($tenant);

    $feature = TenantFeature::factory()->create(['feature_code' => Feature::OnlinePayment]);

    expect($feature->tenant_id)->toBe($tenant->id);
});

it('scopes queries to the current tenant — A never sees B', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    TenantFeature::factory()->create(['feature_code' => Feature::Waitlist]);

    $this->tenants->set($b);
    TenantFeature::factory()->create(['feature_code' => Feature::Reports]);

    expect(TenantFeature::pluck('feature_code')->all())->toBe([Feature::Reports]);

    $this->tenants->set($a);
    expect(TenantFeature::pluck('feature_code')->all())->toBe([Feature::Waitlist]);
});

it('returns 404-equivalent (null) when finding another tenant\'s record', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    $aFeature = TenantFeature::factory()->create(['feature_code' => Feature::Messages]);

    $this->tenants->set($b);

    expect(TenantFeature::find($aFeature->id))->toBeNull();
});

it('overrides an explicit foreign tenant_id supplied on create', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    $feature = TenantFeature::factory()->create([
        'tenant_id' => $b->id,
        'feature_code' => Feature::Documents,
    ]);

    expect($feature->tenant_id)->toBe($a->id);
});
