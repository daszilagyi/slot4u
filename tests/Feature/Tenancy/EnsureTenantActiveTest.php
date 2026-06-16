<?php

use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

function activeTenantUrl(string $slug): string
{
    return 'http://'.$slug.'.'.config('tenancy.central_domain').'/';
}

it('serves the home page for an active tenant', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(activeTenantUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Home'));
});

it('serves the home page for a trial tenant (trial is operational)', function () {
    // TenantStatus::Trial must be treated as operational by EnsureTenantActive.
    Tenant::factory()->trial()->create(['slug' => 'newbie']);

    $this->get(activeTenantUrl('newbie'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Home'));
});

it('shows the suspended status page with a 503 for a suspended tenant', function () {
    Tenant::factory()->suspended()->create(['slug' => 'frozen', 'name' => 'Frozen Co']);

    $this->get(activeTenantUrl('frozen'))
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Suspended')
            ->where('tenantName', 'Frozen Co'));
});

it('returns 404 for an archived (soft-deleted) tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'gone']);
    $tenant->delete();

    $this->get(activeTenantUrl('gone'))->assertNotFound();
});
