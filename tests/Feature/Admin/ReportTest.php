<?php

use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature as Pennant;
use Spatie\Permission\Models\Role as RoleModel;
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
function repTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function repUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

function repBooking(Tenant $tenant, array $overrides = [], ?Service $service = null): Booking
{
    $service ??= Service::factory()->forTenant($tenant)->create();

    return Booking::factory()->forTenant($tenant)->create(array_merge([
        'service_id' => $service->id,
        'status' => BookingStatus::Confirmed,
    ], $overrides));
}

/** The report props of a GET /reports request as a plain array. */
function repProps(User $actor, string $slug, array $query = []): array
{
    app(TenantManager::class)->forget();

    $url = tenantHost($slug, '/reports');
    if ($query !== []) {
        $url .= '?'.http_build_query($query);
    }

    $response = test()->actingAs($actor)->get($url);
    $response->assertOk();

    return $response->viewData('page')['props']['report'];
}

// --- Access ---

it('renders the statistics module for a tenant admin', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/reports'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Reports/Index')
            ->where('report.timezone', 'Europe/Budapest')
            ->has('report.totals')
            ->has('report.previous_totals')
            ->has('report.series')
            ->has('report.by_service')
            ->has('report.by_staff')
            ->has('report.by_room')
            ->has('report.top_customers'));
});

it('denies an employee, who has no report.view in the docs/03 matrix', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $employee = repUser($tenant, Role::Employee);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/reports'))
        ->assertForbidden();
});

it('403s when the tenant does not have the reports feature', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);

    TenantFeature::factory()->create([
        'feature_code' => Feature::Reports,
        'enabled' => false,
    ]);
    // Pennant memoises per request/process, so the override needs a flush here.
    Pennant::flushCache();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/reports'))
        ->assertForbidden();
});

// --- Range (docs/01 §7: the tenant wall clock) ---

it('defaults to the current month on the tenant wall clock', function () {
    $tenant = repTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = repUser($tenant, Role::TenantAdmin);
    // 2026-08-02 10:00 Budapest (CEST, UTC+2).
    $this->travelTo('2026-08-02 08:00:00');

    $report = repProps($admin, 'acme');

    expect($report['from'])->toBe('2026-08-01')
        ->and($report['to'])->toBe('2026-08-02')
        // Month-to-date compares against the same days of the previous month, not
        // against the two days before August.
        ->and($report['previous_from'])->toBe('2026-07-01')
        ->and($report['previous_to'])->toBe('2026-07-02')
        ->and($report['series'])->toHaveCount(2);
});

it('places a booking on the tenant-local day, not the UTC one', function () {
    $tenant = repTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-05 08:00:00');

    // 00:30 local on the 3rd, but still the 2nd in UTC.
    repBooking($tenant, [
        'starts_at' => '2026-08-02 22:30:00',
        'ends_at' => '2026-08-02 23:30:00',
        'price_minor' => 500000,
    ]);

    $report = repProps($admin, 'acme');
    $byDate = collect($report['series'])->keyBy('date');

    expect($byDate['2026-08-03']['revenue_minor'])->toBe(500000)
        ->and($byDate['2026-08-02']['revenue_minor'])->toBe(0);
});

it('rejects a range longer than a year', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/reports?preset=custom&from=2024-01-01&to=2026-01-01'))
        ->assertSessionHasErrors('to');
});

it('reads a reversed custom range as the caller meant it', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);

    $report = repProps($admin, 'acme', [
        'preset' => 'custom',
        'from' => '2026-07-31',
        'to' => '2026-07-01',
    ]);

    expect($report['from'])->toBe('2026-07-01')
        ->and($report['to'])->toBe('2026-07-31');
});

// --- Money (docs/10 §3.1) ---

it('counts only the commission-bearing statuses as revenue', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $slot = ['starts_at' => '2026-08-10 09:00:00', 'ends_at' => '2026-08-10 10:00:00'];

    repBooking($tenant, $slot + ['status' => BookingStatus::Confirmed, 'price_minor' => 100000]);
    repBooking($tenant, $slot + ['status' => BookingStatus::Completed, 'price_minor' => 200000]);
    repBooking($tenant, $slot + ['status' => BookingStatus::NoShow, 'price_minor' => 300000]);
    repBooking($tenant, $slot + ['status' => BookingStatus::Requested, 'price_minor' => 900000]);
    repBooking($tenant, $slot + ['status' => BookingStatus::Canceled, 'price_minor' => 900000]);

    $report = repProps($admin, 'acme');
    $totals = $report['totals'];

    expect($totals['revenue_minor'])->toBe(600000)
        ->and($totals['realized'])->toBe(3)
        ->and($totals['bookings'])->toBe(5)
        ->and($totals['canceled'])->toBe(1)
        ->and($totals['no_show'])->toBe(1)
        ->and($totals['average_value_minor'])->toBe(200000)
        // 1 of 3 realised bookings was a no-show; 1 of 5 bookings was cancelled.
        ->and($totals['no_show_rate_bps'])->toBe(3333)
        ->and($totals['cancel_rate_bps'])->toBe(2000);
});

it('keeps the daily series and the headline revenue in step', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    repBooking($tenant, ['starts_at' => '2026-08-03 09:00:00', 'ends_at' => '2026-08-03 10:00:00', 'price_minor' => 120000]);
    repBooking($tenant, ['starts_at' => '2026-08-09 09:00:00', 'ends_at' => '2026-08-09 10:00:00', 'price_minor' => 340000]);
    repBooking($tenant, ['starts_at' => '2026-08-09 12:00:00', 'ends_at' => '2026-08-09 13:00:00', 'price_minor' => 60000]);

    $report = repProps($admin, 'acme');

    expect(collect($report['series'])->sum('revenue_minor'))
        ->toBe($report['totals']['revenue_minor'])
        ->and(collect($report['series'])->sum('bookings'))
        ->toBe($report['totals']['realized']);
});

it('compares a whole month against the whole previous month', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    // In range (July), and in the comparison range (June).
    repBooking($tenant, ['starts_at' => '2026-07-10 09:00:00', 'ends_at' => '2026-07-10 10:00:00', 'price_minor' => 500000]);
    repBooking($tenant, ['starts_at' => '2026-06-10 09:00:00', 'ends_at' => '2026-06-10 10:00:00', 'price_minor' => 200000]);

    $report = repProps($admin, 'acme', ['preset' => 'last_month']);

    expect($report['from'])->toBe('2026-07-01')
        ->and($report['to'])->toBe('2026-07-31')
        ->and($report['previous_from'])->toBe('2026-06-01')
        ->and($report['previous_to'])->toBe('2026-06-30')
        ->and($report['totals']['revenue_minor'])->toBe(500000)
        ->and($report['previous_totals']['revenue_minor'])->toBe(200000);
});

it('compares a rolling range against the equally long preceding one', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-30 08:00:00');

    $report = repProps($admin, 'acme', ['preset' => 'last_30_days']);

    expect($report['from'])->toBe('2026-08-01')
        ->and($report['to'])->toBe('2026-08-30')
        ->and($report['previous_from'])->toBe('2026-07-02')
        ->and($report['previous_to'])->toBe('2026-07-31');
});

// --- Breakdowns ---

it('breaks realised bookings down by service', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $massage = Service::factory()->forTenant($tenant)->create(['name' => 'Masszázs']);
    $haircut = Service::factory()->forTenant($tenant)->create(['name' => 'Hajvágás']);
    $slot = ['starts_at' => '2026-08-10 09:00:00', 'ends_at' => '2026-08-10 10:00:00'];

    repBooking($tenant, $slot + ['price_minor' => 100000], $massage);
    repBooking($tenant, $slot + ['price_minor' => 300000], $massage);
    repBooking($tenant, $slot + ['price_minor' => 50000], $haircut);
    // Cancelled: neither its count nor its price may appear.
    repBooking($tenant, $slot + ['price_minor' => 999999, 'status' => BookingStatus::Canceled], $haircut);

    $report = repProps($admin, 'acme');
    $rows = collect($report['by_service'])->keyBy('name');

    // Sorted by revenue: the massage leads.
    expect($report['by_service'][0]['name'])->toBe('Masszázs')
        ->and($rows['Masszázs']['bookings'])->toBe(2)
        ->and($rows['Masszázs']['revenue_minor'])->toBe(400000)
        ->and($rows['Hajvágás']['bookings'])->toBe(1)
        ->and($rows['Hajvágás']['revenue_minor'])->toBe(50000);
});

it('ranks customers by spend and marks the guests', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Kiss Anna']);
    $slot = ['starts_at' => '2026-08-10 09:00:00', 'ends_at' => '2026-08-10 10:00:00'];

    repBooking($tenant, $slot + ['customer_id' => $customer->id, 'price_minor' => 100000]);
    repBooking($tenant, $slot + ['customer_id' => $customer->id, 'price_minor' => 150000]);
    repBooking($tenant, $slot + [
        'customer_id' => null,
        'guest_name' => 'Nagy Béla',
        'guest_email' => 'bela@example.test',
        'price_minor' => 400000,
    ]);

    $report = repProps($admin, 'acme');

    expect($report['top_customers'][0])
        ->toMatchArray(['name' => 'Nagy Béla', 'is_guest' => true, 'bookings' => 1, 'spend_minor' => 400000])
        ->and($report['top_customers'][1])
        ->toMatchArray(['name' => 'Kiss Anna', 'is_guest' => false, 'bookings' => 2, 'spend_minor' => 250000])
        // The account holder and the guest are two distinct contacts.
        ->and($report['totals']['customers'])->toBe(2);
});

// --- Utilisation ---

it('divides booked minutes by the schedule the booking engine uses', function () {
    $tenant = repTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $staff = Staff::factory()->forTenant($tenant)->create(['name' => 'Anna']);
    // Open 09:00–17:00 (8h) on Monday only. 2026-08-03 and 08-10 are Mondays.
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(1, '09:00', '17:00')->create();

    // A two-hour booking on the first Monday (07:00 UTC = 09:00 CEST).
    repBooking($tenant, [
        'staff_id' => $staff->id,
        'starts_at' => '2026-08-03 07:00:00',
        'ends_at' => '2026-08-03 09:00:00',
        'price_minor' => 100000,
    ]);

    $report = repProps($admin, 'acme', [
        'preset' => 'custom',
        'from' => '2026-08-03',
        'to' => '2026-08-09',
    ]);

    $row = $report['by_staff'][0];

    expect($row['name'])->toBe('Anna')
        ->and($row['booked_minutes'])->toBe(120)
        // One Monday in the week → one 8h band.
        ->and($row['scheduled_minutes'])->toBe(480)
        ->and($row['utilization_bps'])->toBe(2500);
});

it('lets a day-off exception shrink the capacity, exactly as it shrinks availability', function () {
    $tenant = repTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $staff = Staff::factory()->forTenant($tenant)->create(['name' => 'Anna']);
    // Open 09:00–17:00 on Monday AND Tuesday: 16 hours in the week.
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->onDay(1, '09:00', '17:00')->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->onDay(2, '09:00', '17:00')->create();
    // The Tuesday is off entirely.
    ScheduleException::factory()->forTenant($tenant)->forSchedulable($staff)
        ->create(['date' => '2026-08-04', 'start_time' => null, 'end_time' => null]);

    $report = repProps($admin, 'acme', [
        'preset' => 'custom',
        'from' => '2026-08-03',
        'to' => '2026-08-09',
    ]);

    expect($report['by_staff'][0]['scheduled_minutes'])->toBe(480);
});

it('reports no utilisation rather than zero when a resource has no schedule', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $staff = Staff::factory()->forTenant($tenant)->create(['name' => 'Anna']);
    repBooking($tenant, [
        'staff_id' => $staff->id,
        'starts_at' => '2026-08-10 09:00:00',
        'ends_at' => '2026-08-10 10:00:00',
    ]);

    $report = repProps($admin, 'acme');

    expect($report['by_staff'][0]['scheduled_minutes'])->toBe(0)
        ->and($report['by_staff'][0]['utilization_bps'])->toBeNull();
});

it('clips a booking that straddles the range boundary', function () {
    $tenant = repTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $room = Room::factory()->forTenant($tenant)->create([
        'name' => 'Nagyterem',
        'location_id' => Location::factory()->forTenant($tenant)->create()->id,
    ]);

    // 22:00–02:00 local across the night of the 4th into the 5th; the range ends
    // with the 4th, so only the first two hours are inside it.
    repBooking($tenant, [
        'room_id' => $room->id,
        'starts_at' => '2026-08-04 20:00:00',
        'ends_at' => '2026-08-05 00:00:00',
    ]);

    $report = repProps($admin, 'acme', [
        'preset' => 'custom',
        'from' => '2026-08-01',
        'to' => '2026-08-04',
    ]);

    expect($report['by_room'][0]['booked_minutes'])->toBe(120);
});

// --- Isolation ---

it('never counts another tenant\'s bookings', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    repBooking($tenant, ['starts_at' => '2026-08-10 09:00:00', 'ends_at' => '2026-08-10 10:00:00', 'price_minor' => 100000]);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    repBooking($other, ['starts_at' => '2026-08-10 09:00:00', 'ends_at' => '2026-08-10 10:00:00', 'price_minor' => 999999]);
    app(TenantManager::class)->set($tenant);

    $report = repProps($admin, 'acme');

    expect($report['totals']['revenue_minor'])->toBe(100000)
        ->and($report['totals']['bookings'])->toBe(1);
});

// --- Query cost ---

it('loads the schedule once for the whole range, not once per day', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $staff = Staff::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->onDay(1, '09:00', '17:00')->create();
    // A room too, so both resource panels actually run their loaders.
    Room::factory()->forTenant($tenant)->create([
        'location_id' => Location::factory()->forTenant($tenant)->create()->id,
    ]);
    app(TenantManager::class)->forget();

    DB::enableQueryLog();
    $this->actingAs($admin)
        ->get(tenantHost('acme', '/reports?preset=custom&from=2026-01-01&to=2026-08-01'))
        ->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Two resource types, one schedule + one exception query each — a constant,
    // whatever the range length. A per-day loader would issue hundreds here.
    expect($queries->filter(fn (string $q) => str_contains($q, 'from "schedules"'))->count())->toBe(2)
        ->and($queries->filter(fn (string $q) => str_contains($q, 'from "schedule_exceptions"'))->count())->toBe(2);
});

// --- CSV export ---

it('exports the daily table as CSV', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    repBooking($tenant, ['starts_at' => '2026-08-10 09:00:00', 'ends_at' => '2026-08-10 10:00:00', 'price_minor' => 123400]);
    app(TenantManager::class)->forget();

    $response = $this->actingAs($admin)
        ->get(tenantHost('acme', '/reports/export?section=daily'))
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('2026-08-10;1;1234,00');
});

it('exports the staff table with exact minutes', function () {
    $tenant = repTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = repUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-15 08:00:00');

    $staff = Staff::factory()->forTenant($tenant)->create(['name' => 'Anna']);
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->onDay(1, '09:00', '17:00')->create();
    repBooking($tenant, [
        'staff_id' => $staff->id,
        'starts_at' => '2026-08-03 07:00:00',
        'ends_at' => '2026-08-03 09:00:00',
        'price_minor' => 100000,
    ]);
    app(TenantManager::class)->forget();

    $response = $this->actingAs($admin)
        ->get(tenantHost('acme', '/reports/export?section=staff&preset=custom&from=2026-08-03&to=2026-08-09'))
        ->assertOk();

    expect($response->streamedContent())->toContain('Anna;1;1000,00;120;480;25,00%');
});

it('denies the export to an actor without report.view', function () {
    $tenant = repTenant(['slug' => 'acme']);
    $manager = repUser($tenant, Role::Manager);
    RoleModel::findByName(Role::Manager->value, 'web')
        ->revokePermissionTo(Permission::ReportView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/reports/export'))
        ->assertForbidden();
});
