<?php

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** Active tenant, set as current + team context (so role checks resolve). */
function calTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function calUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

function calBooking(Tenant $tenant, array $overrides = [], ?Staff $staff = null): Booking
{
    $service = Service::factory()->forTenant($tenant)->create();

    return Booking::factory()->forTenant($tenant)->create(array_merge([
        'service_id' => $service->id,
        'staff_id' => $staff?->id,
    ], $overrides));
}

// --- Rendering + range ---

it('defaults to the current week around today', function () {
    $tenant = calTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = calUser($tenant, Role::TenantAdmin);
    // 2026-08-05 is a Wednesday.
    $this->travelTo('2026-08-05 08:00:00');
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Calendar/Index')
            ->where('calendar.view', 'week')
            ->where('calendar.date', '2026-08-05')
            // Weeks start on Monday.
            ->where('calendar.range_start', '2026-08-03')
            ->where('calendar.range_end', '2026-08-09')
            ->where('calendar.prev_date', '2026-07-29')
            ->where('calendar.next_date', '2026-08-12')
            ->has('calendar.columns', 7));
});

it('switches to a single day with resource columns', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    Staff::factory()->forTenant($tenant)->create(['name' => 'Anna']);
    Staff::factory()->forTenant($tenant)->create(['name' => 'Béla']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.view', 'day')
            ->where('calendar.range_start', '2026-08-05')
            ->where('calendar.range_end', '2026-08-05')
            ->where('calendar.prev_date', '2026-08-04')
            ->where('calendar.next_date', '2026-08-06')
            // One column per staff; no unassigned column, nothing lands there.
            ->has('calendar.columns', 2)
            ->where('calendar.columns.0.label', 'Anna')
            ->where('calendar.columns.1.label', 'Béla'));
});

it('adds an unassigned column only when a booking has no resource', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    Staff::factory()->forTenant($tenant)->create(['name' => 'Anna']);
    calBooking($tenant, [
        'staff_id' => null,
        'starts_at' => '2026-08-05 08:00:00',
        'ends_at' => '2026-08-05 09:00:00',
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendar.columns', 2)
            ->where('calendar.columns.1.key', 'staff-none')
            ->where('calendar.events.0.column_key', 'staff-none'));
});

// --- Placement (docs/01 §7) ---

it('places events on the tenant wall clock, not on UTC', function () {
    $tenant = calTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = calUser($tenant, Role::TenantAdmin);

    // 08:00 UTC = 10:00 Budapest (CEST).
    calBooking($tenant, ['starts_at' => '2026-08-05 08:00:00', 'ends_at' => '2026-08-05 09:30:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendar.events', 1)
            ->where('calendar.events.0.date', '2026-08-05')
            ->where('calendar.events.0.start_minute', 600)
            ->where('calendar.events.0.end_minute', 690));
});

it('places a winter booking correctly too (CET, not CEST)', function () {
    $tenant = calTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = calUser($tenant, Role::TenantAdmin);

    // 08:00 UTC = 09:00 Budapest in January (CET, UTC+1) — one hour less than in August.
    calBooking($tenant, ['starts_at' => '2026-01-14 08:00:00', 'ends_at' => '2026-01-14 09:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-01-14'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.events.0.start_minute', 540)
            ->where('calendar.events.0.end_minute', 600));
});

it('positions by wall clock across the spring-forward night', function () {
    $tenant = calTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = calUser($tenant, Role::TenantAdmin);

    // 2026-03-29: the clocks jump 02:00 → 03:00 local. 09:00 UTC is 11:00 local, and
    // it must land on the 11:00 row even though only 10 real hours have passed.
    calBooking($tenant, ['starts_at' => '2026-03-29 09:00:00', 'ends_at' => '2026-03-29 10:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-03-29'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.events.0.start_minute', 660)
            ->where('calendar.events.0.end_minute', 720));
});

it('assigns week-view events to their own day column', function () {
    $tenant = calTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = calUser($tenant, Role::TenantAdmin);

    // 22:30 UTC on the 4th is 00:30 local on the 5th — it belongs to the 5th's column.
    calBooking($tenant, ['starts_at' => '2026-08-04 22:30:00', 'ends_at' => '2026-08-04 23:30:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.events.0.column_key', '2026-08-05')
            ->where('calendar.events.0.start_minute', 30));
});

// --- Window ---

it('widens the grid rather than hiding an early or late booking', function () {
    $tenant = calTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = calUser($tenant, Role::TenantAdmin);

    // 03:20 local start, 23:40 local end — both outside the 06:00–22:00 default.
    calBooking($tenant, ['starts_at' => '2026-08-05 01:20:00', 'ends_at' => '2026-08-05 02:00:00']);
    calBooking($tenant, ['starts_at' => '2026-08-05 20:00:00', 'ends_at' => '2026-08-05 21:40:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Pulled down to the 03:00 hour and out to the 24:00 hour.
            ->where('calendar.window_start_minute', 180)
            ->where('calendar.window_end_minute', 1440));
});

it('keeps the default window when everything fits inside it', function () {
    $tenant = calTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = calUser($tenant, Role::TenantAdmin);
    calBooking($tenant, ['starts_at' => '2026-08-05 08:00:00', 'ends_at' => '2026-08-05 09:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.window_start_minute', 360)
            ->where('calendar.window_end_minute', 1320));
});

// --- Content rules ---

it('drops cancelled and rejected bookings from the grid', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    $slot = ['starts_at' => '2026-08-05 08:00:00', 'ends_at' => '2026-08-05 09:00:00'];

    calBooking($tenant, $slot + ['status' => BookingStatus::Confirmed]);
    calBooking($tenant, $slot + ['status' => BookingStatus::Canceled]);
    calBooking($tenant, $slot + ['status' => BookingStatus::Rejected]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('calendar.events', 1));
});

it('lists a booking with no start time separately instead of dropping it', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-05 08:00:00');

    calBooking($tenant, ['starts_at' => null, 'ends_at' => null]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendar.events', 0)
            ->has('calendar.unscheduled', 1));
});

it('clamps a booking running past midnight to the end of its start day', function () {
    $tenant = calTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = calUser($tenant, Role::TenantAdmin);

    // 22:00 local on the 5th → 02:00 local on the 6th.
    calBooking($tenant, ['starts_at' => '2026-08-05 20:00:00', 'ends_at' => '2026-08-06 00:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.events.0.start_minute', 1320)
            ->where('calendar.events.0.end_minute', 1440)
            // The real end still travels with the row, so the card can state it.
            ->where('calendar.events.0.ends_at', '2026-08-06T00:00:00+00:00'));
});

// --- Filters ---

it('filters the grid by staff, room and service', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $other = Staff::factory()->forTenant($tenant)->create();
    $slot = ['starts_at' => '2026-08-05 08:00:00', 'ends_at' => '2026-08-05 09:00:00'];

    calBooking($tenant, $slot, $staff);
    calBooking($tenant, $slot, $other);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/calendar?view=day&date=2026-08-05&staff_id={$staff->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('calendar.events', 1));
});

it('rejects a filter id belonging to another tenant', function () {
    $other = calTenant(['slug' => 'other']);
    $foreignStaff = Staff::factory()->forTenant($other)->create();

    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    // A foreign id must fail validation, not quietly return an empty grid.
    $this->actingAs($admin)
        ->get(tenantHost('acme', "/calendar?staff_id={$foreignStaff->id}"))
        ->assertSessionHasErrors('staff_id');
});

it('groups the day by room when asked', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    $location = Location::factory()->forTenant($tenant)->create();
    $room = Room::factory()->forTenant($tenant)->create([
        'location_id' => $location->id,
        'name' => 'Nagyterem',
    ]);
    calBooking($tenant, [
        'room_id' => $room->id,
        'starts_at' => '2026-08-05 08:00:00',
        'ends_at' => '2026-08-05 09:00:00',
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&group=room&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.columns.0.label', 'Nagyterem')
            ->where('calendar.events.0.column_key', 'room-'.$room->id));
});

// --- Scoping ---

it('never shows another tenant\'s bookings', function () {
    $other = calTenant(['slug' => 'other']);
    calBooking($other, ['starts_at' => '2026-08-05 08:00:00', 'ends_at' => '2026-08-05 09:00:00']);

    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    calBooking($tenant, ['starts_at' => '2026-08-05 08:00:00', 'ends_at' => '2026-08-05 09:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('calendar.events', 1));
});

it('narrows an employee to their own bookings and columns', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $employee = calUser($tenant, Role::Employee);
    $own = Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    Staff::factory()->forTenant($tenant)->create();
    $slot = ['starts_at' => '2026-08-05 08:00:00', 'ends_at' => '2026-08-05 09:00:00'];

    calBooking($tenant, $slot, $own);
    calBooking($tenant, $slot);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/calendar?view=day&date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendar.events', 1)
            // The colleague's column would always be empty for them, so it is not drawn.
            ->has('calendar.columns', 1)
            ->where('calendar.columns.0.key', 'staff-'.$own->id)
            ->has('options.staff', 1));
});

// --- Access + performance ---

it('requires the booking.view permission', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $customer = calUser($tenant, Role::Customer);
    app(TenantManager::class)->forget();

    // ensure.staff walls the admin panel off from the customer role entirely.
    $this->actingAs($customer)
        ->get(tenantHost('acme', '/calendar'))
        ->assertForbidden();
});

it('keeps the calendar behind authentication', function () {
    calTenant(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $this->get(tenantHost('acme', '/calendar'))->assertRedirect();
});

it('loads a full week without an N+1', function () {
    $tenant = calTenant(['slug' => 'acme']);
    $admin = calUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();

    foreach (range(3, 9) as $day) {
        foreach (range(1, 3) as $i) {
            $customer = User::factory()->create(['tenant_id' => $tenant->id]);
            calBooking($tenant, [
                'customer_id' => $customer->id,
                'starts_at' => sprintf('2026-08-%02d 0%d:00:00', $day, $i + 5),
                'ends_at' => sprintf('2026-08-%02d 0%d:45:00', $day, $i + 5),
            ], $staff);
        }
    }
    app(TenantManager::class)->forget();

    DB::enableQueryLog();
    $this->actingAs($admin)->get(tenantHost('acme', '/calendar?date=2026-08-05'))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // 21 bookings — eager loading means a fixed handful of relation loads, not one
    // per row. Both tables are also hit once for the filter dropdowns, hence the 2:
    // without the eager load these climb into the twenties.
    expect($queries->filter(fn (string $q) => str_contains($q, 'from "services"'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $q) => str_contains($q, 'from "staff"'))->count())->toBeLessThanOrEqual(2);
});
