<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\PrivacyRequestStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PrivacyRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/*
 * The tenant's data-subject request queue (SLO-159, docs/19): where the tenant,
 * as controller, decides on what its customers asked for.
 *
 * Every guardrail is probed with a direct request rather than through the UI
 * that hides the control — the button being absent is a courtesy, the route
 * refusing is the guarantee.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function queueTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function queueStaff(Tenant $tenant, string $role = Role::TenantAdmin->value): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

function queueCustomer(Tenant $tenant, array $attributes = []): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create([...$attributes, 'tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

it('lists the tenant requests with pending ones first', function () {
    $tenant = queueTenant();
    $admin = queueStaff($tenant);
    $customer = queueCustomer($tenant, ['name' => 'Kovács Anna']);

    PrivacyRequest::factory()->forTenant($tenant)->completed()->create(['user_id' => $customer->id]);
    PrivacyRequest::factory()->forTenant($tenant)->create(['user_id' => $customer->id]);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Privacy/Index')
            ->has('requests', 2)
            // The queue is a to-do list before it is an archive.
            ->where('requests.0.status', 'pending')
            ->where('requests.0.subject.name', 'Kovács Anna'));
});

it('refuses the queue without privacy.manage', function () {
    $tenant = queueTenant();
    // Manager holds most of the matrix but not this code — it is seeded to
    // tenant-admin only and the tenant has to grant it on purpose.
    $manager = queueStaff($tenant, Role::Manager->value);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/settings/privacy'))
        ->assertForbidden();
});

it('lets a tenant grant privacy.manage to another role', function () {
    $tenant = queueTenant();
    $manager = queueStaff($tenant, Role::Manager->value);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    RoleModel::findByName(Role::Manager->value)
        ->givePermissionTo(Permission::PrivacyManage->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Fresh instance: actingAs binds the memoized model, which still carries
    // the pre-grant permission set.
    $manager = User::query()->findOrFail($manager->id);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/settings/privacy'))
        ->assertOk();
});

it('404s a request belonging to another tenant', function () {
    $mine = queueTenant('acme');
    $admin = queueStaff($mine);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $otherCustomer = User::factory()->create(['tenant_id' => $other->id]);

    // The current tenant has to BE the other one while the row is created:
    // BelongsToTenant stamps `tenant_id` from TenantManager and overrides any
    // caller-supplied value, so a `forTenant($other)` factory call under `$mine`
    // would quietly produce one of MY rows and the test would prove nothing.
    app(TenantManager::class)->set($other);
    $foreign = PrivacyRequest::factory()->create(['user_id' => $otherCustomer->id]);
    expect($foreign->tenant_id)->toBe($other->id);

    app(TenantManager::class)->set($mine);
    app(PermissionRegistrar::class)->setPermissionsTeamId($mine->getKey());

    // 404, not 403: a cross-tenant probe must not confirm the row exists.
    $this->actingAs($admin)
        ->post(tenantHost('acme', "/settings/privacy/{$foreign->id}/approve"))
        ->assertNotFound();
});

it('executes the erasure when the tenant approves', function () {
    $tenant = queueTenant();
    $admin = queueStaff($tenant);
    $customer = queueCustomer($tenant, ['name' => 'Kovács Anna', 'email' => 'anna@example.test']);
    $request = PrivacyRequest::factory()->forTenant($tenant)->create(['user_id' => $customer->id]);

    $this->actingAs($admin)
        ->from(tenantHost('acme', '/settings/privacy'))
        ->post(tenantHost('acme', "/settings/privacy/{$request->id}/approve"))
        ->assertRedirect(tenantHost('acme', '/settings/privacy'));

    $request->refresh();
    $customer->refresh();

    expect($request->status)->toBe(PrivacyRequestStatus::Completed)
        ->and($request->resolved_by_id)->toBe($admin->id)
        ->and($request->resolved_at)->not->toBeNull()
        ->and($customer->anonymized_at)->not->toBeNull()
        ->and($customer->email)->not->toBe('anna@example.test');

    expect(AuditLog::query()->where('action', AuditAction::PrivacyErasureCompleted->value)->exists())->toBeTrue();
});

it('never writes the erased values into the audit trail', function () {
    $tenant = queueTenant();
    $admin = queueStaff($tenant);
    $customer = queueCustomer($tenant, ['name' => 'Kovács Anna', 'email' => 'anna@example.test']);
    $request = PrivacyRequest::factory()->forTenant($tenant)->create(['user_id' => $customer->id]);

    $this->actingAs($admin)->post(tenantHost('acme', "/settings/privacy/{$request->id}/approve"));

    // Prove the erasure actually ran — otherwise "no erased values in the trail"
    // is trivially true because nothing was erased.
    expect($customer->fresh()->anonymized_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::PrivacyErasureCompleted->value)->exists())->toBeTrue();

    // An audit row holding what was just erased would put it straight back.
    $trail = AuditLog::query()->get()->map(fn (AuditLog $row): string => json_encode([
        $row->old_values, $row->new_values,
    ], JSON_UNESCAPED_UNICODE))->implode(' ');

    expect($trail)->not->toContain('Kovács Anna')
        ->and($trail)->not->toContain('anna@example.test');
});

it('requires a reason to refuse a request', function () {
    $tenant = queueTenant();
    $admin = queueStaff($tenant);
    $customer = queueCustomer($tenant);
    $request = PrivacyRequest::factory()->forTenant($tenant)->create(['user_id' => $customer->id]);

    // Art. 12 (4): the subject has to be told why. A refusal recorded without
    // one leaves a register that cannot answer the only question asked of it.
    $this->actingAs($admin)
        ->from(tenantHost('acme', '/settings/privacy'))
        ->post(tenantHost('acme', "/settings/privacy/{$request->id}/reject"), [])
        ->assertSessionHasErrors('reason');

    expect($request->fresh()->status)->toBe(PrivacyRequestStatus::Pending);
});

it('records a refusal with its reason and leaves the data intact', function () {
    $tenant = queueTenant();
    $admin = queueStaff($tenant);
    $customer = queueCustomer($tenant, ['email' => 'anna@example.test']);
    $request = PrivacyRequest::factory()->forTenant($tenant)->create(['user_id' => $customer->id]);

    $this->actingAs($admin)
        ->from(tenantHost('acme', '/settings/privacy'))
        ->post(tenantHost('acme', "/settings/privacy/{$request->id}/reject"), [
            'reason' => 'Folyamatban lévő jogvita miatt az adatokat meg kell őriznünk.',
        ])
        ->assertRedirect(tenantHost('acme', '/settings/privacy'));

    $request->refresh();
    $customer->refresh();

    expect($request->status)->toBe(PrivacyRequestStatus::Rejected)
        ->and($request->resolution_note)->toContain('jogvita')
        ->and($request->resolved_by_id)->toBe($admin->id)
        // A refusal must not touch the data.
        ->and($customer->anonymized_at)->toBeNull()
        ->and($customer->email)->toBe('anna@example.test');

    expect(AuditLog::query()->where('action', AuditAction::PrivacyErasureRejected->value)->exists())->toBeTrue();
});

it('refuses to resolve an already resolved request', function () {
    $tenant = queueTenant();
    $admin = queueStaff($tenant);
    $customer = queueCustomer($tenant);
    $request = PrivacyRequest::factory()->forTenant($tenant)->rejected()->create(['user_id' => $customer->id]);

    // A resolved request is a compliance record; reopening one would make the
    // register worth less than not having it.
    $this->actingAs($admin)
        ->post(tenantHost('acme', "/settings/privacy/{$request->id}/approve"))
        ->assertForbidden();

    expect($customer->fresh()->anonymized_at)->toBeNull();
});
