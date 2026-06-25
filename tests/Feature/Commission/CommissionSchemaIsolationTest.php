<?php

declare(strict_types=1);

use App\Models\CommissionInvoice;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Models\TenantCommissionOverride;
use App\Tenancy\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * Tenant isolation for the commission schema (docs/10 §5, §12/21). Mirrors the
 * BelongsToTenant contract proven by tests/Feature/Tenancy/BelongsToTenantTest:
 * a cross-tenant lookup resolves to null at the model layer (the route layer
 * turns that into 404, not 403). CommissionSetting is platform-level and is
 * deliberately NOT tenant-scoped.
 */

beforeEach(function () {
    $this->tenants = app(TenantManager::class);
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

// Each entry creates one row for whatever tenant is currently bound.
dataset('tenant_owned', [
    'commission_invoice' => [fn () => CommissionInvoice::create(['period' => '2026-06'])],
    'tenant_billing_period' => [fn () => TenantBillingPeriod::create(['period' => '2026-06'])],
    'tenant_commission_override' => [fn () => TenantCommissionOverride::create([])],
]);

it('auto-fills tenant_id from the current tenant on create', function (Closure $create) {
    $tenant = Tenant::factory()->create();
    $this->tenants->set($tenant);

    $row = $create();

    expect($row->tenant_id)->toBe($tenant->id);
})->with('tenant_owned');

it('isolates rows between tenants and 404s cross-tenant lookups', function (Closure $create) {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    $aRow = $create();

    $this->tenants->set($b);
    $create();

    // From B's context: only B's row is visible, and A's record is not findable.
    expect($aRow::query()->pluck('tenant_id')->all())->toBe([$b->id])
        ->and($aRow::find($aRow->getKey()))->toBeNull();

    // From A's context: only A's row is visible.
    $this->tenants->set($a);
    expect($aRow::query()->pluck('tenant_id')->all())->toBe([$a->id]);
})->with('tenant_owned');

it('sees every tenant row when no tenant is bound (superadmin/console)', function (Closure $create) {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    $aRow = $create();
    $this->tenants->set($b);
    $create();

    $this->tenants->forget();

    expect($aRow::query()->count())->toBe(2);
})->with('tenant_owned');

it('overrides a forged foreign tenant_id on create', function () {
    // Security: a caller must not be able to forge ownership by passing a
    // foreign tenant_id. tenant_id is not fillable and the trait stamps the
    // currently-bound tenant unconditionally.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $this->tenants->set($a);

    $invoice = CommissionInvoice::create(['period' => '2026-06', 'tenant_id' => $b->id]);

    expect($invoice->tenant_id)->toBe($a->id);
});

it('enforces one billing period per tenant per period', function () {
    $a = Tenant::factory()->create();
    $this->tenants->set($a);

    TenantBillingPeriod::create(['period' => '2026-06']);

    expect(fn () => TenantBillingPeriod::create(['period' => '2026-06']))
        ->toThrow(QueryException::class);
});

it('enforces one invoice per tenant per period', function () {
    $a = Tenant::factory()->create();
    $this->tenants->set($a);

    CommissionInvoice::create(['period' => '2026-06']);

    expect(fn () => CommissionInvoice::create(['period' => '2026-06']))
        ->toThrow(QueryException::class);
});

it('does not tenant-scope platform commission settings', function () {
    $setting = CommissionSetting::factory()->create();

    $this->tenants->set(Tenant::factory()->create());

    // Visible even inside a tenant context — no global scope on the platform table.
    expect(CommissionSetting::find($setting->id))->not->toBeNull();
});

it('has no tenant_id column on commission_settings', function () {
    expect(Schema::hasColumn('commission_settings', 'tenant_id'))->toBeFalse();
});

it('stores money as integer minor units and rates as integer bps', function () {
    $setting = CommissionSetting::factory()->create();

    expect($setting->free_threshold_minor)->toBeInt()
        ->and($setting->rate_bps)->toBeInt()
        ->and($setting->monthly_cap_minor)->toBeInt();
});
