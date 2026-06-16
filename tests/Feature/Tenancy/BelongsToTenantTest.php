<?php

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantThing;

beforeEach(function () {
    if (! Schema::hasTable('tenant_things')) {
        Schema::create('tenant_things', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('name');
            $table->timestamps();
        });
    }

    $this->tenants = app(TenantManager::class);
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

it('auto-fills tenant_id from the current tenant on create', function () {
    $tenant = Tenant::factory()->create();
    $this->tenants->set($tenant);

    $thing = TenantThing::create(['name' => 'Widget']);

    expect($thing->tenant_id)->toBe($tenant->id);
});

it('scopes queries to the current tenant — A never sees B', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    TenantThing::create(['name' => 'A thing']);

    $this->tenants->set($b);
    TenantThing::create(['name' => 'B thing']);

    expect(TenantThing::pluck('name')->all())->toBe(['B thing']);

    $this->tenants->set($a);
    expect(TenantThing::pluck('name')->all())->toBe(['A thing']);
});

it('returns null when finding another tenant\'s record', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    $aThing = TenantThing::create(['name' => 'A thing']);

    $this->tenants->set($b);

    expect(TenantThing::find($aThing->id))->toBeNull();
});

it('is a no-op when no tenant is bound (console/superadmin context)', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    TenantThing::create(['name' => 'A thing']);
    $this->tenants->set($b);
    TenantThing::create(['name' => 'B thing']);

    $this->tenants->forget();

    expect(TenantThing::count())->toBe(2);
});

it('overrides an explicit foreign tenant_id supplied on create', function () {
    // Security: a controller must not be able to forge ownership by passing an
    // arbitrary tenant_id in fillable data. The trait must ignore any supplied
    // value and stamp the currently-bound tenant unconditionally.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);

    $thing = TenantThing::create(['name' => 'Forged', 'tenant_id' => $b->id]);

    expect($thing->tenant_id)->toBe($a->id);
    // DB-level double check: the row truly belongs to A, not B.
    $this->tenants->forget();
    expect(TenantThing::where('id', $thing->id)->value('tenant_id'))->toBe($a->id);
});

it('query-builder update is confined to the current tenant', function () {
    // A bulk update via the query builder must not touch rows belonging to
    // another tenant — the TenantScope WHERE clause must be applied.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    $aThing = TenantThing::create(['name' => 'A thing']);

    $this->tenants->set($b);
    TenantThing::create(['name' => 'B thing']);

    // While in tenant B context, try to rename everything to 'hijacked'.
    TenantThing::query()->update(['name' => 'hijacked']);

    // Only B's row should change; A's row must be untouched.
    $this->tenants->forget();
    expect(TenantThing::where('id', $aThing->id)->value('name'))->toBe('A thing');
});

it('withoutGlobalScope bypasses tenant isolation — callers must handle this consciously', function () {
    // Documents that withoutGlobalScope is the escape hatch for superadmin /
    // cross-tenant operations and DOES leak data. This is intentional behaviour,
    // but code reaching for it must be explicitly aware of the risk.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenants->set($a);
    $aThing = TenantThing::create(['name' => 'A thing']);

    // Switch to tenant B context.
    $this->tenants->set($b);

    // Normal query sees nothing from A.
    expect(TenantThing::find($aThing->id))->toBeNull();

    // Escape hatch query sees A's row even from B's context.
    $found = TenantThing::withoutGlobalScope(TenantScope::class)
        ->find($aThing->id);

    expect($found)->not->toBeNull();
    expect($found->tenant_id)->toBe($a->id);
});
