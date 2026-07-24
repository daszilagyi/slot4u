<?php

use App\Enums\CommissionInvoiceStatus;
use App\Enums\Role;
use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

/*
 * Suspended-tenant billing access (SLO-120, docs/03): a tenant suspended for
 * non-payment must still reach its commission invoices to see what to settle and
 * clear the suspension — the /billing routes therefore live outside
 * ensure.tenant.active, while the public surface and the rest of the admin panel
 * stay 503. Access control (auth + staff + billing.view) is unchanged.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-07-16 09:00:00');
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function suspendedBillingUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

it('lets a suspended tenant admin reach the billing page', function () {
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'acme']);
    $admin = suspendedBillingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Billing/Index')
            ->where('suspended', true));
});

it('marks the billing page not-suspended for an active tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = suspendedBillingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('suspended', false));
});

it('still 503s the public surface of a suspended tenant', function () {
    Tenant::factory()->suspended()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/'))
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Suspended')
            ->where('canAccessBilling', false));
});

it('still 503s the rest of the admin panel of a suspended tenant', function () {
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'acme']);
    $admin = suspendedBillingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Suspended'));
});

it('still enforces billing.view on a suspended tenant (manager forbidden)', function () {
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'acme']);
    $manager = suspendedBillingUser($tenant, Role::Manager);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/billing'))
        ->assertForbidden();
});

it('404s another tenant\'s invoice PDF for a suspended tenant admin (isolation preserved)', function () {
    // Moving /billing out of ensure.tenant.active must not weaken the route-bound
    // {invoice} tenant scope: a suspended tenant admin still cannot reach another
    // tenant's invoice (BelongsToTenant global scope → cross-tenant 404).
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'acme']);
    $admin = suspendedBillingUser($tenant, Role::TenantAdmin);

    $otherTenant = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignInvoice = CommissionInvoice::factory()->create([
        'tenant_id' => $otherTenant->id,
        'status' => CommissionInvoiceStatus::Issued,
    ]);

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/billing/invoices/{$foreignInvoice->id}/pdf"))
        ->assertNotFound();
});

it('redirects a guest from the suspended billing page to login', function () {
    Tenant::factory()->suspended()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/billing'))
        ->assertRedirectContains('/login');
});

it('offers a billing link on the suspended page to a logged-in member', function () {
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'acme']);
    $admin = suspendedBillingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/'))
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Suspended')
            ->where('canAccessBilling', true));
});
