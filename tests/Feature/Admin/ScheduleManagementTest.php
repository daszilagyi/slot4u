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
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A user with the given role in the given tenant. */
function scheduleUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

/** Minimal valid weekly band payload for a staff member. */
function bandPayload(Staff $staff, array $overrides = []): array
{
    return array_merge([
        'schedulable_type' => 'staff',
        'schedulable_id' => $staff->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'location_id' => null,
        'valid_from' => null,
        'valid_until' => null,
    ], $overrides);
}

it('lists schedulables, schedules and exceptions', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Room::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->create();
    ScheduleException::factory()->forTenant($tenant)->forSchedulable($staff)->create();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/schedule'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Schedule/Index')
            ->has('schedulables', 2)
            ->has('schedules', 1)
            ->has('exceptions', 1)
            ->where('exceptionTypes', ScheduleExceptionType::values())
            ->where('conflicts', []));
});

it('creates a weekly band for a staff member', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), bandPayload($staff, [
            'day_of_week' => 2,
            'start_time' => '10:00',
            'end_time' => '14:30',
        ]))
        ->assertRedirect();

    $this->assertDatabaseHas('schedules', [
        'tenant_id' => $tenant->id,
        'schedulable_type' => 'staff',
        'schedulable_id' => $staff->id,
        'day_of_week' => 2,
        // The test DB (SQLite :memory:) stores the H:i string verbatim; prod
        // MariaDB returns H:i:s. The controller's substr(…, 0, 5) normalises both.
        'start_time' => '10:00',
        'end_time' => '14:30',
    ]);
});

it('creates a band for a room with a location', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $room = Room::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), [
            'schedulable_type' => 'room',
            'schedulable_id' => $room->id,
            'day_of_week' => 3,
            'start_time' => '08:00',
            'end_time' => '20:00',
            'location_id' => $room->location_id,
            'valid_from' => null,
            'valid_until' => null,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('schedules', [
        'schedulable_type' => 'room',
        'schedulable_id' => $room->id,
        'location_id' => $room->location_id,
    ]);
});

it('rejects a band whose end is not after its start (no midnight crossing)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), bandPayload($staff, [
            'start_time' => '22:00',
            'end_time' => '02:00',
        ]))
        ->assertSessionHasErrors('end_time');
});

it('rejects an equal start and end time', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), bandPayload($staff, [
            'start_time' => '09:00',
            'end_time' => '09:00',
        ]))
        ->assertSessionHasErrors('end_time');
});

it('rejects a band overlapping an existing one on the same day', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(1, '09:00', '12:00')->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), bandPayload($staff, [
            'day_of_week' => 1,
            'start_time' => '11:00',
            'end_time' => '15:00',
        ]))
        ->assertSessionHasErrors('start_time');
});

it('allows adjacent (touching) bands on the same day', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(1, '09:00', '12:00')->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), bandPayload($staff, [
            'day_of_week' => 1,
            'start_time' => '12:00',
            'end_time' => '15:00',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('allows overlapping times when validity windows do not overlap', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(1, '09:00', '12:00')
        ->create(['valid_from' => '2026-01-01', 'valid_until' => '2026-03-31']);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), bandPayload($staff, [
            'day_of_week' => 1,
            'start_time' => '10:00',
            'end_time' => '13:00',
            'valid_from' => '2026-04-01',
            'valid_until' => '2026-06-30',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('lets a band be updated to overlap only its own former slot', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $band = Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(1, '09:00', '12:00')->create();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/schedule/entries/{$band->id}"), bandPayload($staff, [
            'day_of_week' => 1,
            'start_time' => '09:30',
            'end_time' => '11:00',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(substr($band->fresh()->start_time, 0, 5))->toBe('09:30');
});

it('stores time as a wall-clock value regardless of app timezone (DST-safe)', function () {
    // Times describe a recurring local pattern, not an instant: the stored value
    // must not be shifted by UTC conversion (docs/01 §7, docs/04 DST edge case).
    config(['app.timezone' => 'UTC']);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();

    // 2026-03-29 is the spring-forward DST date in Europe/Budapest.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), bandPayload($staff, [
            'start_time' => '02:30',
            'end_time' => '03:30',
            'valid_from' => '2026-03-29',
        ]))
        ->assertRedirect();

    $band = Schedule::firstOrFail();
    expect(substr($band->start_time, 0, 5))->toBe('02:30')
        ->and(substr($band->end_time, 0, 5))->toBe('03:30');
});

it('copies a day schedule onto target days, replacing their bands', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(1, '09:00', '12:00')->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(1, '13:00', '17:00')->create();
    // A stale band on the target day that must be replaced.
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(2, '08:00', '10:00')->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/copy-day'), [
            'schedulable_type' => 'staff',
            'schedulable_id' => $staff->id,
            'source_day' => 1,
            'target_days' => [2, 3],
        ])
        ->assertRedirect();

    expect(Schedule::where('schedulable_id', $staff->id)->where('day_of_week', 2)->count())->toBe(2)
        ->and(Schedule::where('schedulable_id', $staff->id)->where('day_of_week', 3)->count())->toBe(2)
        ->and(Schedule::where('day_of_week', 2)->where('start_time', '08:00:00')->exists())->toBeFalse();
});

it('deletes a band', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $band = Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/schedule/entries/{$band->id}"))
        ->assertRedirect();

    $this->assertDatabaseMissing('schedules', ['id' => $band->id]);
});

it('creates an all-day off exception', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/exceptions'), [
            'schedulable_type' => 'staff',
            'schedulable_id' => $staff->id,
            'date' => '2026-08-20',
            'type' => 'off',
            'start_time' => null,
            'end_time' => null,
            'note' => 'Ünnep',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('schedule_exceptions', [
        'schedulable_id' => $staff->id,
        'date' => '2026-08-20',
        'type' => 'off',
        'note' => 'Ünnep',
    ]);
});

it('rejects an extra opening without a time range', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/exceptions'), [
            'schedulable_type' => 'staff',
            'schedulable_id' => $staff->id,
            'date' => '2026-08-20',
            'type' => 'extra',
            'start_time' => null,
            'end_time' => null,
        ])
        ->assertSessionHasErrors('start_time');
});

it('deletes an exception', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $exception = ScheduleException::factory()->forTenant($tenant)->forSchedulable($staff)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/schedule/exceptions/{$exception->id}"))
        ->assertRedirect();

    $this->assertDatabaseMissing('schedule_exceptions', ['id' => $exception->id]);
});

it('rejects a schedulable from another tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignStaff = Staff::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/schedule/entries'), bandPayload($foreignStaff))
        ->assertSessionHasErrors('schedulable_id');
});

it('404s when updating another tenant\'s band (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignStaff = Staff::factory()->forTenant($other)->create();
    $foreignBand = Schedule::factory()->forTenant($other)->forSchedulable($foreignStaff)->create();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/schedule/entries/{$foreignBand->id}"), bandPayload($foreignStaff))
        ->assertNotFound();
});

it('404s when deleting another tenant\'s exception (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignStaff = Staff::factory()->forTenant($other)->create();
    $foreignException = ScheduleException::factory()->forTenant($other)
        ->forSchedulable($foreignStaff)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/schedule/exceptions/{$foreignException->id}"))
        ->assertNotFound();

    $this->assertDatabaseHas('schedule_exceptions', ['id' => $foreignException->id]);
});

it('never lists another tenant\'s schedules or resources', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = scheduleUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->create();

    // Another tenant's data must not leak into acme's listing.
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $otherStaff = Staff::factory()->forTenant($other)->create();
    Staff::factory()->forTenant($other)->create();
    Schedule::factory()->forTenant($other)->forSchedulable($otherStaff)->create();
    ScheduleException::factory()->forTenant($other)->forSchedulable($otherStaff)->create();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/schedule'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('schedulables', 1)
            ->has('schedules', 1)
            ->has('exceptions', 0));
});

it('grants a manager access to schedules', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $manager = scheduleUser($tenant, Role::Manager);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/schedule'))
        ->assertOk();
});

it('forbids a customer without schedule.manage (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = scheduleUser($tenant, Role::Customer);

    $this->actingAs($customer)
        ->get(tenantHost('acme', '/schedule'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/schedule'))->assertRedirectContains('/login');
});
