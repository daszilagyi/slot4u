<?php

use App\Enums\AuditAction;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature as Pennant;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/*
 * Custom tenant roles and per-user overrides (SLO-142, docs/03): the tenant
 * defines its own roles and moves a single user's grant on top of them.
 *
 * The headline acceptance criterion is that a user holding ONLY a custom role
 * can work in the admin panel at all — before this the staff check tested the
 * three seeded role names, which such a user could never satisfy. Every
 * guardrail is probed with a direct request rather than through the UI that
 * hides the control.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** Active tenant, set as current + team context (so role checks resolve). */
function rbacTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

/** A user of the tenant holding the given role names. */
function rbacUser(Tenant $tenant, string ...$roles): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

/**
 * A role of the tenant's own, created the way the editor would and granted the
 * given codes.
 *
 * @param  list<Permission>  $permissions
 */
function rbacCustomRole(Tenant $tenant, string $name, array $permissions = []): RoleModel
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    /** @var RoleModel $role */
    $role = RoleModel::create([
        'name' => $name,
        'guard_name' => 'web',
        app(PermissionRegistrar::class)->teamsKey => $tenant->getKey(),
    ]);

    $role->syncPermissions(array_map(static fn (Permission $p): string => $p->value, $permissions));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role;
}

/**
 * A freshly loaded copy of the user, for acting AFTER their grant was changed.
 *
 * `actingAs()` puts the given instance straight into the guard, so a model whose
 * roles/permissions relations were already touched keeps serving the memoized
 * copy across requests. A real request always re-resolves the user from the
 * session, so this restores what production does rather than papering over it.
 */
function rbacReload(User $user): User
{
    return User::query()->findOrFail($user->getKey());
}

/** The tenant's own row for a role name. */
function rbacRoleRow(Tenant $tenant, string $name): ?RoleModel
{
    /** @var RoleModel|null */
    return RoleModel::query()
        ->where(app(PermissionRegistrar::class)->teamsKey, $tenant->getKey())
        ->where('name', $name)
        ->first();
}

// --- The headline AC: a custom role is a working admin-panel role ---

it('lets a user holding only a custom role into the admin panel', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós', [Permission::BookingView]);
    $user = rbacUser($tenant, 'Recepciós');
    app(TenantManager::class)->forget();

    // The panel itself opens (this is the check that used to 403 on a name miss)
    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk();

    // ...and the user has exactly the role's grant, no more.
    $this->actingAs($user)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/services'))
        ->assertForbidden();
});

it('still keeps a pure customer out of the admin panel', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $customer = rbacUser($tenant, Role::Customer->value);
    app(TenantManager::class)->forget();

    $this->actingAs($customer)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertForbidden();
});

it('treats a user with a custom role as staff for the shared prop too', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós', [Permission::BookingView]);
    $user = rbacUser($tenant, 'Recepciós');
    app(TenantManager::class)->forget();

    // The middleware and the prop must agree: an admin nav rendered for a user
    // the middleware rejects would 403 on every click.
    $this->actingAs($user)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_staff', true));
});

// --- Creating a role ---

it('creates a custom role with no permissions', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles'), ['name' => 'Recepciós'])
        ->assertRedirect();

    $role = rbacRoleRow($tenant, 'Recepciós');

    expect($role)->not->toBeNull()
        ->and($role->permissions()->count())->toBe(0);
});

it('refuses a built-in role name', function (string $name) {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles'), ['name' => $name])
        ->assertSessionHasErrors('name');
})->with(['tenant-admin', 'manager', 'employee', 'customer', 'super-admin']);

it('refuses a name the tenant already uses', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós');
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles'), ['name' => 'Recepciós'])
        ->assertSessionHasErrors('name');
});

it('lets two tenants use the same custom role name', function () {
    $other = rbacTenant(['slug' => 'other']);
    rbacCustomRole($other, 'Recepciós');

    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles'), ['name' => 'Recepciós'])
        ->assertSessionHasNoErrors();

    expect(rbacRoleRow($tenant, 'Recepciós'))->not->toBeNull();
});

it('records the creation in the audit trail', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles'), ['name' => 'Recepciós']);

    expect(AuditLog::query()->where('action', AuditAction::RoleCreated->value)->exists())->toBeTrue();
});

it('is closed to a manager without role.manage', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $manager = rbacUser($tenant, Role::Manager->value);
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->post(tenantHost('acme', '/settings/roles'), ['name' => 'Recepciós'])
        ->assertForbidden();
});

// --- Renaming ---

it('renames a custom role and keeps its holders', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós', [Permission::BookingView]);
    $user = rbacUser($tenant, 'Recepciós');
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->patch(tenantHost('acme', '/settings/roles/'.rawurlencode('Recepciós').'/name'), [
            'name' => 'Front office',
        ])
        ->assertRedirect();

    expect(rbacRoleRow($tenant, 'Recepciós'))->toBeNull()
        ->and(rbacRoleRow($tenant, 'Front office'))->not->toBeNull();

    // The assignment travelled with the row, so the grant still applies.
    $this->actingAs($user)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk();
});

it('refuses to rename a built-in role', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->patch(tenantHost('acme', '/settings/roles/'.Role::Manager->value.'/name'), [
            'name' => 'Vezető',
        ])
        ->assertForbidden();

    expect(rbacRoleRow($tenant, Role::Manager->value))->not->toBeNull();
});

it('accepts a rename that does not change the name', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós');
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    // The uniqueness rule must exclude the row being renamed, or re-saving an
    // unchanged name would fail.
    $this->actingAs($admin)
        ->patch(tenantHost('acme', '/settings/roles/'.rawurlencode('Recepciós').'/name'), [
            'name' => 'Recepciós',
        ])
        ->assertSessionHasNoErrors();
});

// --- Deleting ---

it('deletes an empty custom role', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós');
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', '/settings/roles/'.rawurlencode('Recepciós')))
        ->assertRedirect();

    expect(rbacRoleRow($tenant, 'Recepciós'))->toBeNull();
});

it('refuses to delete a role that still has holders', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós', [Permission::BookingView]);
    $holder = rbacUser($tenant, 'Recepciós');
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', '/settings/roles/'.rawurlencode('Recepciós')))
        ->assertSessionHasErrors('role');

    expect(rbacRoleRow($tenant, 'Recepciós'))->not->toBeNull();

    // The holder is untouched — the point of the refusal.
    $this->actingAs($holder)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk();
});

it('refuses to delete a built-in role', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', '/settings/roles/'.Role::Employee->value))
        ->assertForbidden();

    expect(rbacRoleRow($tenant, Role::Employee->value))->not->toBeNull();
});

it('refuses to delete the role the actor holds', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Fő admin', [Permission::RoleManage]);
    $admin = rbacUser($tenant, 'Fő admin');
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', '/settings/roles/'.rawurlencode('Fő admin')))
        ->assertForbidden();
});

it('refuses to reset a custom role to a default it never had', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós');
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles/'.rawurlencode('Recepciós').'/reset'))
        ->assertForbidden();
});

it('cannot touch another tenant role of the same name', function () {
    $other = rbacTenant(['slug' => 'other']);
    rbacCustomRole($other, 'Recepciós', [Permission::BookingView]);

    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    // acme has no such role: a probe must 404, not reach the other tenant's row.
    $this->actingAs($admin)
        ->delete(tenantHost('acme', '/settings/roles/'.rawurlencode('Recepciós')))
        ->assertNotFound();

    expect(rbacRoleRow($other, 'Recepciós'))->not->toBeNull();
});

// --- Per-user overrides ---

it('lists the tenant staff with their grant, customers excluded', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    rbacUser($tenant, Role::Employee->value);
    rbacUser($tenant, Role::Customer->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/roles'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('users', 2));
});

it('assigns a role to a user and it applies on the very next request', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $employee = rbacUser($tenant, Role::Employee->value);
    app(TenantManager::class)->forget();

    // The seeded employee cannot see reports (docs/03 matrix).
    $this->actingAs($employee)
        ->get(tenantHost('acme', '/reports'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$employee->id}/rbac"), [
            'roles' => [Role::Manager->value],
            'permissions' => [],
        ])
        ->assertRedirect();

    $this->actingAs(rbacReload($employee))
        ->get(tenantHost('acme', '/reports'))
        ->assertOk();
});

it('grants an individual permission on top of the role', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $employee = rbacUser($tenant, Role::Employee->value);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/services'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$employee->id}/rbac"), [
            'roles' => [Role::Employee->value],
            'permissions' => [Permission::ServiceManage->value],
        ])
        ->assertRedirect();

    $this->actingAs(rbacReload($employee))
        ->get(tenantHost('acme', '/services'))
        ->assertOk();

    // ...and the role itself is untouched: this was an individual grant.
    $colleague = rbacUser($tenant, Role::Employee->value);
    app(TenantManager::class)->forget();

    $this->actingAs($colleague)
        ->get(tenantHost('acme', '/services'))
        ->assertForbidden();
});

it('revokes an individual permission it no longer sends', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $employee = rbacUser($tenant, Role::Employee->value);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee->givePermissionTo(Permission::ServiceManage->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$employee->id}/rbac"), [
            'roles' => [Role::Employee->value],
            'permissions' => [],
        ])
        ->assertRedirect();

    $this->actingAs(rbacReload($employee))
        ->get(tenantHost('acme', '/services'))
        ->assertForbidden();
});

it('refuses an admin-reserved code as a direct permission', function (string $code) {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $employee = rbacUser($tenant, Role::Employee->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$employee->id}/rbac"), [
            'roles' => [Role::Employee->value],
            'permissions' => [$code],
        ])
        ->assertSessionHasErrors('permissions.0');
})->with([
    Permission::BillingView->value,
    Permission::BillingEdit->value,
    Permission::RoleManage->value,
]);

it('refuses to assign the customer role from the staff editor', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $employee = rbacUser($tenant, Role::Employee->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$employee->id}/rbac"), [
            'roles' => [Role::Customer->value],
            'permissions' => [],
        ])
        ->assertSessionHasErrors('roles.0');
});

it('refuses an empty role list', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $employee = rbacUser($tenant, Role::Employee->value);
    app(TenantManager::class)->forget();

    // Zero roles is not "no permissions", it is a silent lockout from the panel.
    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$employee->id}/rbac"), [
            'roles' => [],
            'permissions' => [],
        ])
        ->assertSessionHasErrors('roles');
});

it('refuses to edit the actor themselves', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$admin->id}/rbac"), [
            'roles' => [Role::Employee->value],
            'permissions' => [],
        ])
        ->assertForbidden();
});

it('404s on a user of another tenant', function () {
    $other = rbacTenant(['slug' => 'other']);
    $stranger = rbacUser($other, Role::Employee->value);

    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$stranger->id}/rbac"), [
            'roles' => [Role::Employee->value],
            'permissions' => [],
        ])
        ->assertNotFound();
});

it('404s on a customer of the same tenant', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $customer = rbacUser($tenant, Role::Customer->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$customer->id}/rbac"), [
            'roles' => [Role::Employee->value],
            'permissions' => [],
        ])
        ->assertNotFound();
});

it('keeps a customer role the editor may not show', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    // Both staff and a customer of their own tenant — they book for themselves.
    $hybrid = rbacUser($tenant, Role::Employee->value, Role::Customer->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$hybrid->id}/rbac"), [
            'roles' => [Role::Manager->value],
            'permissions' => [],
        ])
        ->assertRedirect();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $names = $hybrid->fresh()->roles()->pluck('name')->sort()->values()->all();

    expect($names)->toBe([Role::Customer->value, Role::Manager->value]);
});

it('keeps a feature-locked direct grant across a save', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $employee = rbacUser($tenant, Role::Employee->value);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee->givePermissionTo(Permission::ReportView->value);

    // Turning the feature off makes the code unofferable — the form can no
    // longer send it back, so the server has to preserve it.
    TenantFeature::factory()->create(['feature_code' => Feature::Reports, 'enabled' => false]);
    Pennant::flushCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$employee->id}/rbac"), [
            'roles' => [Role::Employee->value],
            'permissions' => [],
        ])
        ->assertRedirect();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $codes = $employee->fresh()->permissions()->pluck('name')->all();

    expect($codes)->toContain(Permission::ReportView->value);
});

it('records the user change in the audit trail', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $admin = rbacUser($tenant, Role::TenantAdmin->value);
    $employee = rbacUser($tenant, Role::Employee->value);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/settings/users/{$employee->id}/rbac"), [
            'roles' => [Role::Manager->value],
            'permissions' => [Permission::ServiceManage->value],
        ]);

    $log = AuditLog::query()->where('action', AuditAction::UserRbacUpdated->value)->sole();

    expect($log->old_values['roles'])->toBe([Role::Employee->value])
        ->and($log->new_values['roles'])->toBe([Role::Manager->value])
        ->and($log->new_values['permissions'])->toBe([Permission::ServiceManage->value]);
});

// --- customer.view_all: the own-scope is a code now, not a role name ---

it('lets a custom role see the whole customer roster with customer.view_all', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós', [
        Permission::CustomerView,
        Permission::CustomerViewAll,
    ]);
    $user = rbacUser($tenant, 'Recepciós');
    $customer = rbacUser($tenant, Role::Customer->value);
    app(TenantManager::class)->forget();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));

    expect($customer->exists)->toBeTrue();
});

it('own-scopes a role without customer.view_all', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    rbacCustomRole($tenant, 'Recepciós', [Permission::CustomerView]);
    $user = rbacUser($tenant, 'Recepciós');
    rbacUser($tenant, Role::Customer->value);
    app(TenantManager::class)->forget();

    // No staff record links this user to anybody, so the own-scope is empty.
    $this->actingAs($user)
        ->get(tenantHost('acme', '/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
});

it('keeps the seeded manager unrestricted and the employee own-scoped', function () {
    $tenant = rbacTenant(['slug' => 'acme']);
    $manager = rbacUser($tenant, Role::Manager->value);
    $employee = rbacUser($tenant, Role::Employee->value);
    rbacUser($tenant, Role::Customer->value);
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));

    expect(Staff::query()->count())->toBe(0)
        ->and(Booking::query()->count())->toBe(0);
});
