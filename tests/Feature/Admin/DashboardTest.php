<?php

use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
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
function dbTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function dbUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

/** A booking of the tenant with a fresh same-tenant service. */
function dbBooking(Tenant $tenant, array $overrides = [], ?Staff $staff = null): Booking
{
    $service = Service::factory()->forTenant($tenant)->create();

    return Booking::factory()->forTenant($tenant)->create(array_merge([
        'service_id' => $service->id,
        'staff_id' => $staff?->id,
    ], $overrides));
}

// --- Rendering ---

it('renders the bento dashboard for a tenant admin', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->has('dashboard.date')
            ->has('dashboard.calendar_month')
            ->where('dashboard.timezone', 'Europe/Budapest')
            ->has('dashboard.agenda')
            ->has('dashboard.recent')
            ->has('dashboard.calendar'));
});

// --- Tenant timezone boundaries (docs/01 §7) ---

it('places "today" on the tenant wall clock, not on UTC', function () {
    $tenant = dbTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = dbUser($tenant, Role::TenantAdmin);

    // 2026-08-02 10:00 Budapest (CEST, UTC+2).
    $this->travelTo('2026-08-02 08:00:00');

    // 23:30 local — still today.
    dbBooking($tenant, ['starts_at' => '2026-08-02 21:30:00', 'ends_at' => '2026-08-02 22:00:00']);
    // 00:30 local on the 3rd — already tomorrow, even though it is still the 2nd in UTC.
    dbBooking($tenant, ['starts_at' => '2026-08-02 22:30:00', 'ends_at' => '2026-08-02 23:00:00']);
    // 01:30 local on the 2nd — today, though it is still the 1st in UTC.
    dbBooking($tenant, ['starts_at' => '2026-08-01 23:30:00', 'ends_at' => '2026-08-02 00:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.date', '2026-08-02')
            ->where('dashboard.bookings_today', 2)
            ->has('dashboard.agenda', 2));
});

it('buckets the month calendar on tenant-local days', function () {
    $tenant = dbTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');

    // Both are the 3rd in Budapest (00:30 and 09:00 local), the first still the 2nd in UTC.
    dbBooking($tenant, ['starts_at' => '2026-08-02 22:30:00', 'ends_at' => '2026-08-02 23:00:00']);
    dbBooking($tenant, ['starts_at' => '2026-08-03 07:00:00', 'ends_at' => '2026-08-03 08:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $page->where('dashboard.calendar_month', '2026-08')
                // August has 31 days and every one of them is present, zero or not.
                ->has('dashboard.calendar', 31);

            $days = collect($page->toArray()['props']['dashboard']['calendar'])
                ->keyBy('date');

            expect($days['2026-08-03']['count'])->toBe(2)
                ->and($days['2026-08-02']['count'])->toBe(0);
        });
});

// --- Revenue ---

it('sums only the commission-bearing statuses into today\'s revenue', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');

    $today = ['starts_at' => '2026-08-02 09:00:00', 'ends_at' => '2026-08-02 10:00:00'];

    // Billable (docs/10 §3.1): confirmed + completed + no-show.
    dbBooking($tenant, $today + ['status' => BookingStatus::Confirmed, 'price_minor' => 100000]);
    dbBooking($tenant, $today + ['status' => BookingStatus::Completed, 'price_minor' => 200000]);
    dbBooking($tenant, $today + ['status' => BookingStatus::NoShow, 'price_minor' => 300000]);
    // Not yet revenue.
    dbBooking($tenant, $today + ['status' => BookingStatus::Requested, 'price_minor' => 900000]);
    dbBooking($tenant, $today + ['status' => BookingStatus::PendingPayment, 'price_minor' => 900000]);
    dbBooking($tenant, $today + ['status' => BookingStatus::Canceled, 'price_minor' => 900000]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.revenue_today_minor', 600000)
            ->where('dashboard.confirmed_today', 3)
            // Cancelled and rejected are off the day; the other four still count.
            ->where('dashboard.bookings_today', 5));
});

it('places a booking with no start time on the day it was taken', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');

    // The no_time_slot mode has no starts_at, so the created_at fallback applies.
    dbBooking($tenant, ['starts_at' => null, 'ends_at' => null, 'price_minor' => 250000]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.bookings_today', 1)
            ->where('dashboard.revenue_today_minor', 250000)
            ->has('dashboard.agenda', 1)
            ->where('dashboard.agenda.0.starts_at', null));
});

it('caps the agenda but still reports the true day total', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');

    // More than the agenda's own limit, so the panel has to be truncated.
    foreach (range(1, 15) as $i) {
        $minute = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        dbBooking($tenant, [
            'starts_at' => "2026-08-02 09:{$minute}:00",
            'ends_at' => "2026-08-02 10:{$minute}:00",
        ]);
    }
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('dashboard.agenda', 12)
            // The tile keeps the honest number, so the page can say what it hid.
            ->where('dashboard.bookings_today', 15)
            // Truncation takes the tail, not the head: the day still starts at 09:01.
            ->where('dashboard.agenda.0.starts_at', '2026-08-02T09:01:00+00:00'));
});

it('sorts bookings without a start time to the end of the agenda', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');

    dbBooking($tenant, ['starts_at' => null, 'ends_at' => null]);
    dbBooking($tenant, ['starts_at' => '2026-08-02 09:00:00', 'ends_at' => '2026-08-02 10:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('dashboard.agenda', 2)
            ->where('dashboard.agenda.0.starts_at', '2026-08-02T09:00:00+00:00')
            ->where('dashboard.agenda.1.starts_at', null));
});

// --- Open work items ---

it('counts pending approvals and pending payments regardless of date', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');

    // Deliberately far from today: an old request is exactly what the tile must surface.
    dbBooking($tenant, ['status' => BookingStatus::Requested, 'starts_at' => '2026-07-20 09:00:00']);
    dbBooking($tenant, ['status' => BookingStatus::Requested, 'starts_at' => '2026-09-20 09:00:00']);
    dbBooking($tenant, ['status' => BookingStatus::PendingPayment, 'starts_at' => '2026-09-21 09:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.pending_approval', 2)
            ->where('dashboard.pending_payment', 1));
});

// --- Scoping ---

it('never shows another tenant\'s bookings', function () {
    $other = dbTenant(['slug' => 'other']);
    dbBooking($other, ['starts_at' => '2026-08-02 09:00:00', 'ends_at' => '2026-08-02 10:00:00']);

    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');
    dbBooking($tenant, ['starts_at' => '2026-08-02 09:00:00', 'ends_at' => '2026-08-02 10:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.bookings_today', 1)
            ->has('dashboard.agenda', 1)
            ->has('dashboard.recent', 1));
});

it('narrows an employee to their own bookings', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $employee = dbUser($tenant, Role::Employee);
    $own = Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $colleague = Staff::factory()->forTenant($tenant)->create();
    $this->travelTo('2026-08-02 08:00:00');

    $today = ['starts_at' => '2026-08-02 09:00:00', 'ends_at' => '2026-08-02 10:00:00', 'price_minor' => 100000];
    dbBooking($tenant, $today, $own);
    dbBooking($tenant, $today, $colleague);
    dbBooking($tenant, $today, $colleague);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.bookings_today', 1)
            ->where('dashboard.revenue_today_minor', 100000)
            ->has('dashboard.agenda', 1)
            ->has('dashboard.recent', 1));
});

it('drops the blocks the actor has no permission for instead of showing zeros', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $employee = dbUser($tenant, Role::Employee);

    // A tenant admin may customise the Employee role (docs/03) — take booking.view away.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    RoleModel::findByName(Role::Employee->value, 'web')
        ->revokePermissionTo(Permission::BookingView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    dbBooking($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.bookings_today', null)
            ->where('dashboard.revenue_today_minor', null)
            ->where('dashboard.agenda', null)
            ->where('dashboard.recent', null)
            ->where('dashboard.calendar', null)
            // customer.view is untouched, so that half of the grid survives.
            ->where('dashboard.customers_total', 0));
});

// --- Customers ---

it('counts the customer roster and this month\'s newcomers', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');

    foreach (['2026-06-10 09:00:00', '2026-08-01 09:00:00', '2026-08-02 07:00:00'] as $createdAt) {
        $customer = User::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $createdAt]);
        $customer->assignRole(Role::Customer->value);
    }
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.customers_total', 3)
            ->where('dashboard.customers_new_this_month', 2));
});

// --- Performance ---

it('loads the booking panels without an N+1', function () {
    $tenant = dbTenant(['slug' => 'acme']);
    $admin = dbUser($tenant, Role::TenantAdmin);
    $this->travelTo('2026-08-02 08:00:00');

    $staff = Staff::factory()->forTenant($tenant)->create();
    foreach (range(1, 6) as $i) {
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        dbBooking($tenant, [
            'customer_id' => $customer->id,
            'starts_at' => "2026-08-02 0{$i}:00:00",
            'ends_at' => "2026-08-02 0{$i}:30:00",
        ], $staff);
    }
    app(TenantManager::class)->forget();

    // Count the per-relation loads inside a single request: eager loading means a
    // fixed number of them however many rows the panels hold. (Comparing query
    // counts across two requests is flaky — the first warms the permission cache.)
    DB::enableQueryLog();
    $this->actingAs($admin)->get(tenantHost('acme', '/dashboard'))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Two panels (agenda + recent) × three relations, eager-loaded once each.
    expect($queries->filter(fn (string $q) => str_contains($q, 'from "services"'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $q) => str_contains($q, 'from "staff"'))->count())->toBeLessThanOrEqual(4);
});

// --- Access ---

it('keeps the dashboard behind authentication', function () {
    dbTenant(['slug' => 'acme']);
    app(TenantManager::class)->forget();

    $this->get(tenantHost('acme', '/dashboard'))->assertRedirect();
});
