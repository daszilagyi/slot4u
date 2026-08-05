<?php

use App\Enums\AuditAction;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditLog;
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
 * Tenant role permission editor (SLO-141, docs/03): the tenant admin reshapes
 * what the manager and employee roles may do. Covers the access control, the
 * "applies immediately" acceptance criterion, and every guardrail — each probed
 * with a direct request, not through the UI that hides the control.
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
function roleTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function roleUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

/**
 * The tenant's own row for a role name. The team column is `tenant_id` here, not
 * spatie's default `team_id` (config/permission.php).
 */
function roleRow(Tenant $tenant, Role $role): RoleModel
{
    return RoleModel::query()
        ->where(app(PermissionRegistrar::class)->teamsKey, $tenant->getKey())
        ->where('name', $role->value)
        ->firstOrFail();
}

/** The role's current permission codes, straight from the pivot. */
function roleCodes(Tenant $tenant, Role $role): array
{
    $codes = roleRow($tenant, $role)->permissions()->pluck('name')->all();
    sort($codes);

    return $codes;
}

// --- Access control ---

it('renders the editor for a tenant admin', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/roles'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Roles/Index')
            ->has('roles', 4)
            ->where('roles.0.name', Role::TenantAdmin->value)
            // The tenant admin is looking at its own role: locked, and locked
            // for the "it is yours" reason as much as the "it is the admin" one.
            ->where('roles.0.editable', false)
            ->where('roles.0.is_own', true)
            ->where('roles.1.name', Role::Manager->value)
            ->where('roles.1.editable', true)
            ->where('roles.1.customized', false)
            ->where('roles.3.name', Role::Customer->value)
            ->where('roles.3.editable', false)
        );
});

it('is closed to a manager without role.manage', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $manager = roleUser($tenant, Role::Manager);
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/settings/roles'))
        ->assertForbidden();
});

it('never offers the admin-reserved codes in the catalog', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/roles'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $codes = collect($page->toArray()['props']['groups'])
                ->flatMap(fn (array $group) => array_column($group['permissions'], 'code'))
                ->all();

            expect($codes)
                ->not->toContain(Permission::BillingView->value)
                ->not->toContain(Permission::BillingEdit->value)
                ->not->toContain(Permission::RoleManage->value)
                ->toContain(Permission::BookingView->value)
                ->toContain(Permission::SettingsEdit->value);
        });
});

// --- The customization applies immediately (the headline AC) ---

it('applies a widened grant to the role members on the very next request', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    $manager = roleUser($tenant, Role::Manager);
    app(TenantManager::class)->forget();

    // The seeded manager cannot manage services (docs/03 matrix).
    $this->actingAs($manager)
        ->get(tenantHost('acme', '/services'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Manager->value), [
            'permissions' => [
                Permission::BookingView->value,
                Permission::ServiceManage->value,
            ],
        ])
        ->assertRedirect();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/services'))
        ->assertOk();
});

it('applies a narrowed grant to the role members on the very next request', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    $manager = roleUser($tenant, Role::Manager);
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk();

    // Everything except booking.view.
    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Manager->value), [
            'permissions' => [Permission::CustomerView->value],
        ])
        ->assertRedirect();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/bookings'))
        ->assertForbidden();
});

it('replaces the grant rather than merging into it', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Employee->value), [
            'permissions' => [Permission::BookingView->value],
        ])
        ->assertRedirect();

    expect(roleCodes($tenant, Role::Employee))->toBe([Permission::BookingView->value]);
});

it('accepts an empty grant', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Employee->value), ['permissions' => []])
        ->assertRedirect();

    expect(roleCodes($tenant, Role::Employee))->toBe([]);
});

it('rejects a submission with no permissions key at all', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Employee->value), [])
        ->assertSessionHasErrors('permissions');
});

// --- Guardrails ---

it('refuses to edit the tenant-admin role', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::TenantAdmin->value), [
            'permissions' => [Permission::BookingView->value],
        ])
        ->assertForbidden();

    // Untouched: still the full grant.
    expect(roleCodes($tenant, Role::TenantAdmin))->toHaveCount(count(Permission::cases()));
});

it('refuses to edit the customer role', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Customer->value), [
            'permissions' => [Permission::BookingView->value],
        ])
        ->assertForbidden();

    // The members area runs on ownership policies, not on these codes (SLO-86).
    expect(roleCodes($tenant, Role::Customer))->toBe([]);
});

it('refuses to let an actor edit the role they hold themselves', function () {
    $tenant = roleTenant(['slug' => 'acme']);

    // A manager who was handed role.manage: they may open the editor, but not
    // turn it on their own role — that is "you cannot take away your own rights".
    roleRow($tenant, Role::Manager)->givePermissionTo(Permission::RoleManage->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $manager = roleUser($tenant, Role::Manager);
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/settings/roles'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles.1.name', Role::Manager->value)
            ->where('roles.1.editable', false)
            ->where('roles.1.is_own', true)
            // Somebody else's role is still theirs to shape.
            ->where('roles.2.name', Role::Employee->value)
            ->where('roles.2.editable', true)
        );

    $this->actingAs($manager)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Manager->value), [
            'permissions' => [Permission::BookingView->value],
        ])
        ->assertForbidden();
});

it('rejects an admin-reserved code with a validation error', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Manager->value), [
            'permissions' => [Permission::BookingView->value, Permission::BillingView->value],
        ])
        ->assertSessionHasErrors('permissions.1');

    expect(roleCodes($tenant, Role::Manager))->not->toContain(Permission::BillingView->value);
});

it('rejects role.manage so a role cannot grant itself everything', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Manager->value), [
            'permissions' => [Permission::RoleManage->value],
        ])
        ->assertSessionHasErrors('permissions.0');
});

it('404s on an unknown role name', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/nope'), ['permissions' => []])
        ->assertNotFound();
});

it('cannot reach another tenant role of the same name', function () {
    $other = roleTenant(['slug' => 'other']);
    // A role that exists only in the other tenant's team.
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->getKey());
    RoleModel::findOrCreate('reception', 'web');

    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    // Cross-tenant probe: 404, not 403 (docs/01 §1).
    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/reception'), ['permissions' => []])
        ->assertNotFound();
});

// --- Feature-locked codes ---

it('marks a code whose feature is off as locked instead of hiding it', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);

    TenantFeature::factory()->create(['feature_code' => Feature::Reports, 'enabled' => false]);
    Pennant::flushCache();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/roles'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $permissions = collect($page->toArray()['props']['groups'])
                ->flatMap(fn (array $group) => $group['permissions'])
                ->keyBy('code');

            expect($permissions[Permission::ReportView->value]['locked_by'])
                ->toBe(Feature::Reports->value);
            expect($permissions[Permission::BookingView->value]['locked_by'])->toBeNull();
        });
});

it('rejects granting a code whose feature is off', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);

    TenantFeature::factory()->create(['feature_code' => Feature::Reports, 'enabled' => false]);
    Pennant::flushCache();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Employee->value), [
            'permissions' => [Permission::ReportView->value],
        ])
        ->assertSessionHasErrors('permissions.0');
});

it('preserves a feature-locked grant the editor could not show', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);

    // The manager holds report.view by default; the feature then goes off, so
    // the form can no longer send it back.
    expect(roleCodes($tenant, Role::Manager))->toContain(Permission::ReportView->value);

    TenantFeature::factory()->create(['feature_code' => Feature::Reports, 'enabled' => false]);
    Pennant::flushCache();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Manager->value), [
            'permissions' => [Permission::BookingView->value],
        ])
        ->assertRedirect();

    // Editing something unrelated must not silently revoke it.
    expect(roleCodes($tenant, Role::Manager))->toBe([
        Permission::BookingView->value,
        Permission::ReportView->value,
    ]);
});

// --- Reset ---

it('restores the seeded default grant', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Manager->value), [
            'permissions' => [Permission::BookingView->value],
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles/'.Role::Manager->value.'/reset'))
        ->assertRedirect();

    $defaults = array_map(
        fn (Permission $permission): string => $permission->value,
        Role::Manager->permissions(),
    );
    sort($defaults);

    expect(roleCodes($tenant, Role::Manager))->toBe($defaults);
});

it('refuses to reset a locked role', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles/'.Role::TenantAdmin->value.'/reset'))
        ->assertForbidden();
});

// --- Audit ---

it('records the old and new grant in the audit trail', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    $before = roleCodes($tenant, Role::Employee);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Employee->value), [
            'permissions' => [Permission::BookingView->value],
        ])
        ->assertRedirect();

    $log = AuditLog::query()
        ->where('action', AuditAction::RolePermissionsUpdated->value)
        ->latest('id')
        ->firstOrFail();

    expect($log->tenant_id)->toBe($tenant->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->old_values['permissions'])->toBe($before)
        ->and($log->new_values['permissions'])->toBe([Permission::BookingView->value])
        ->and($log->new_values['role'])->toBe(Role::Employee->value);
});

it('records a reset under its own action code', function () {
    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/roles/'.Role::Manager->value.'/reset'))
        ->assertRedirect();

    expect(AuditLog::query()->where('action', AuditAction::RolePermissionsReset->value)->count())
        ->toBe(1);
});

// --- Tenant isolation ---

it('leaves another tenant role untouched when editing its own', function () {
    $other = roleTenant(['slug' => 'other']);
    $otherBefore = roleCodes($other, Role::Manager);

    $tenant = roleTenant(['slug' => 'acme']);
    $admin = roleUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/roles/'.Role::Manager->value), [
            'permissions' => [Permission::BookingView->value],
        ])
        ->assertRedirect();

    expect(roleCodes($tenant, Role::Manager))->toBe([Permission::BookingView->value]);
    expect(roleCodes($other, Role::Manager))->toBe($otherBefore);
});
