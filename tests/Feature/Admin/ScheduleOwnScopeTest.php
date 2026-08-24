<?php

use App\Enums\Role;
use App\Enums\ScheduleExceptionType;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/**
 * The employee "saját" scope on the weekly schedule (docs/03 matrix, SLO-177).
 *
 * Until this landed, `schedule.manage` was an all-or-nothing grant that the
 * employee role held in full: an employee could open /schedule and rewrite any
 * colleague's — or any room's — working hours. The matrix always said "saját".
 * The distinction is now the `schedule.manage_all` code (the SLO-142 lesson:
 * never a role NAME, or a tenant's custom role could never be widened).
 *
 * tenantHost() lives in tests/Pest.php.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A user holding the given role in the given tenant. */
function scopeUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

/**
 * A tenant with an employee linked to their own staff record, plus a colleague
 * and a room the employee has no business touching.
 *
 * @return array{0: Tenant, 1: User, 2: Staff, 3: Staff, 4: Room}
 */
function ownScopeFixture(): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $employee = scopeUser($tenant, Role::Employee);
    $mine = Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $theirs = Staff::factory()->forTenant($tenant)->create();
    $room = Room::factory()->forTenant($tenant)->create();

    return [$tenant, $employee, $mine, $theirs, $room];
}

/** Minimal valid weekly band payload. */
function scopeBand(string $type, int $id, array $overrides = []): array
{
    return array_merge([
        'schedulable_type' => $type,
        'schedulable_id' => $id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'location_id' => null,
        'valid_from' => null,
        'valid_until' => null,
    ], $overrides);
}

/** Minimal valid exception payload (a whole-day closure). */
function scopeException(string $type, int $id, array $overrides = []): array
{
    return array_merge([
        'schedulable_type' => $type,
        'schedulable_id' => $id,
        'date' => '2026-09-01',
        'type' => ScheduleExceptionType::Off->value,
        'start_time' => null,
        'end_time' => null,
        'note' => null,
    ], $overrides);
}

// ---------------------------------------------------------------- reading

it('shows an employee only their own staff, bands and exceptions', function () {
    [$tenant, $employee, $mine, $theirs, $room] = ownScopeFixture();

    Schedule::factory()->forTenant($tenant)->forSchedulable($mine)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($theirs)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($room)->create();
    ScheduleException::factory()->forTenant($tenant)->forSchedulable($mine)->create();
    ScheduleException::factory()->forTenant($tenant)->forSchedulable($theirs)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/schedule'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Schedule/Index')
            // Own staff only — the colleague and the room are gone.
            ->has('schedulables', 1)
            ->where('schedulables.0.type', 'staff')
            ->where('schedulables.0.id', $mine->id)
            ->has('schedules', 1)
            ->where('schedules.0.schedulable_id', $mine->id)
            ->has('exceptions', 1)
            ->where('exceptions.0.schedulable_id', $mine->id));
});

it('shows an employee with no staff link an empty schedule', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $employee = scopeUser($tenant, Role::Employee);
    $theirs = Staff::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($theirs)->create();
    ScheduleException::factory()->forTenant($tenant)->forSchedulable($theirs)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/schedule'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('schedulables', 0)
            ->has('schedules', 0)
            ->has('exceptions', 0)
            // Drives the empty-state copy: "ask an admin to link you", not
            // "add a staff member first" — advice an employee cannot act on.
            ->where('restricted', true));
});

it('leaves the manager unrestricted', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $manager = scopeUser($tenant, Role::Manager);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Room::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/schedule'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('schedulables', 2)
            ->has('schedules', 1)
            ->where('restricted', false));
});

// ------------------------------------------------------------- own writes

it('lets an employee manage their own band end to end', function () {
    [$tenant, $employee, $mine] = ownScopeFixture();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', '/schedule/entries'), scopeBand('staff', $mine->id))
        ->assertRedirect();

    $band = Schedule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($band->schedulable_id)->toBe($mine->id);

    $this->actingAs($employee)
        ->put(tenantHost('acme', "/schedule/entries/{$band->id}"), scopeBand('staff', $mine->id, ['end_time' => '18:00']))
        ->assertRedirect();

    $this->actingAs($employee)
        ->delete(tenantHost('acme', "/schedule/entries/{$band->id}"))
        ->assertRedirect();

    expect(Schedule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('lets an employee manage their own exception', function () {
    [$tenant, $employee, $mine] = ownScopeFixture();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', '/schedule/exceptions'), scopeException('staff', $mine->id))
        ->assertRedirect();

    $exception = ScheduleException::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $this->actingAs($employee)
        ->delete(tenantHost('acme', "/schedule/exceptions/{$exception->id}"))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

// ---------------------------------------------------------- foreign writes

it('rejects an employee creating a band for a colleague or a room', function (string $type) {
    [$tenant, $employee, $mine, $theirs, $room] = ownScopeFixture();
    $id = $type === 'room' ? $room->id : $theirs->id;
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', '/schedule/entries'), scopeBand($type, $id))
        ->assertSessionHasErrors('schedulable_id');

    expect(Schedule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
})->with(['a colleague' => 'staff', 'a room' => 'room']);

it('rejects an employee creating an exception for a colleague or a room', function (string $type) {
    [$tenant, $employee, $mine, $theirs, $room] = ownScopeFixture();
    $id = $type === 'room' ? $room->id : $theirs->id;
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', '/schedule/exceptions'), scopeException($type, $id))
        ->assertSessionHasErrors('schedulable_id');

    expect(ScheduleException::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
})->with(['a colleague' => 'staff', 'a room' => 'room']);

it('rejects an employee copying a colleague day', function () {
    [$tenant, $employee, $mine, $theirs] = ownScopeFixture();
    Schedule::factory()->forTenant($tenant)->forSchedulable($theirs)->create(['day_of_week' => 1]);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', '/schedule/copy-day'), [
            'schedulable_type' => 'staff',
            'schedulable_id' => $theirs->id,
            'source_day' => 1,
            'target_days' => [2],
        ])
        ->assertSessionHasErrors('schedulable_id');

    expect(Schedule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('rejects an employee moving their own band onto a colleague', function () {
    [$tenant, $employee, $mine, $theirs] = ownScopeFixture();
    $band = Schedule::factory()->forTenant($tenant)->forSchedulable($mine)->create();
    app(TenantManager::class)->forget();

    // The band binds fine — it IS theirs. The scope has to be re-checked against
    // the SUBMITTED target, or an owned band becomes a way to write a colleague's.
    $this->actingAs($employee)
        ->put(tenantHost('acme', "/schedule/entries/{$band->id}"), scopeBand('staff', $theirs->id))
        ->assertSessionHasErrors('schedulable_id');

    expect($band->refresh()->schedulable_id)->toBe($mine->id);
});

it('404s when an employee updates a band outside their scope', function (string $type) {
    [$tenant, $employee, $mine, $theirs, $room] = ownScopeFixture();
    $owner = $type === 'room' ? $room : $theirs;
    $band = Schedule::factory()->forTenant($tenant)->forSchedulable($owner)->create();
    app(TenantManager::class)->forget();

    // 404, not 403 — the ownership scope hides existence, like a cross-tenant id.
    $this->actingAs($employee)
        ->put(tenantHost('acme', "/schedule/entries/{$band->id}"), scopeBand($type, $owner->id))
        ->assertNotFound();
})->with(['a colleague' => 'staff', 'a room' => 'room']);

it('404s when an employee deletes a band outside their scope', function () {
    [$tenant, $employee, $mine, $theirs] = ownScopeFixture();
    $band = Schedule::factory()->forTenant($tenant)->forSchedulable($theirs)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->delete(tenantHost('acme', "/schedule/entries/{$band->id}"))
        ->assertNotFound();

    expect(Schedule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('404s when an employee deletes an exception outside their scope', function () {
    [$tenant, $employee, $mine, $theirs] = ownScopeFixture();
    $exception = ScheduleException::factory()->forTenant($tenant)->forSchedulable($theirs)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->delete(tenantHost('acme', "/schedule/exceptions/{$exception->id}"))
        ->assertNotFound();

    expect(ScheduleException::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

// ---------------------------------------------------------------- the code

it('does not grant schedule.manage_all to the employee role', function () {
    $tenant = Tenant::factory()->active()->create();
    $employee = scopeUser($tenant, Role::Employee);
    $manager = scopeUser($tenant, Role::Manager);

    expect($employee->hasPermissionTo('schedule.manage'))->toBeTrue()
        ->and($employee->hasPermissionTo('schedule.manage_all'))->toBeFalse()
        ->and($manager->hasPermissionTo('schedule.manage_all'))->toBeTrue();
});

it('widens an employee given schedule.manage_all directly', function () {
    [$tenant, $employee, $mine, $theirs] = ownScopeFixture();
    Schedule::factory()->forTenant($tenant)->forSchedulable($theirs)->create();

    // The whole point of a code over a role name (SLO-142): a tenant can widen
    // one person without moving them into the manager role.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee->givePermissionTo('schedule.manage_all');
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/schedule'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('schedules', 1));
});

it('backfills schedule.manage_all onto the roles that already behaved that way', function () {
    $tenant = Tenant::factory()->active()->create();
    $permissionId = DB::table('permissions')->where('name', 'schedule.manage_all')->value('id');

    // Rewind to the pre-migration state: the code exists, but no role holds it.
    DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (require database_path('migrations/2026_08_24_000001_add_schedule_manage_all_permission.php'))->up();

    $holders = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->where('role_has_permissions.permission_id', $permissionId)
        ->where('roles.tenant_id', $tenant->getKey())
        ->pluck('roles.name')
        ->sort()->values()->all();

    // The two roles that managed the whole tenant before — and nobody else. The
    // employee losing reach here is the point of SLO-177, not a migration bug.
    expect($holders)->toBe(['manager', 'tenant-admin']);
});

it('is idempotent when the migration runs twice', function () {
    Tenant::factory()->active()->create();
    $migration = require database_path('migrations/2026_08_24_000001_add_schedule_manage_all_permission.php');
    $migration->up();
    $migration->up();

    $permissionId = DB::table('permissions')->where('name', 'schedule.manage_all')->value('id');

    expect(DB::table('permissions')->where('name', 'schedule.manage_all')->count())->toBe(1)
        ->and(DB::table('role_has_permissions')->where('permission_id', $permissionId)->count())
        ->toBe(DB::table('roles')->whereIn('name', ['tenant-admin', 'manager'])->count());
});
