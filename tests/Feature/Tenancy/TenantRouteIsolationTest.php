<?php

use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
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

    // A representative tenant-scoped resource route. The global scope makes the
    // lookup tenant-aware, so a foreign record resolves to 404, not 403.
    // The {tenant} domain param is passed first, then the {id} path param.
    Route::middleware(['identify.tenant', 'ensure.tenant.active'])
        ->domain('{tenant}.'.config('tenancy.central_domain'))
        ->get('/things/{id}', fn (string $tenant, string $id) => TenantThing::findOrFail($id));
});

it('exposes a record on its owning tenant subdomain', function () {
    $central = config('tenancy.central_domain');
    $a = Tenant::factory()->active()->create(['slug' => 'alpha']);
    $thing = TenantThing::create(['tenant_id' => $a->id, 'name' => 'Alpha widget']);

    $this->getJson("http://alpha.{$central}/things/{$thing->id}")
        ->assertOk()
        ->assertJsonPath('name', 'Alpha widget');
});

it('returns 404 when another tenant requests the record (cross-tenant)', function () {
    $central = config('tenancy.central_domain');
    $a = Tenant::factory()->active()->create(['slug' => 'alpha']);
    Tenant::factory()->active()->create(['slug' => 'bravo']);
    $thing = TenantThing::create(['tenant_id' => $a->id, 'name' => 'Alpha widget']);

    $this->getJson("http://bravo.{$central}/things/{$thing->id}")
        ->assertNotFound();
});
