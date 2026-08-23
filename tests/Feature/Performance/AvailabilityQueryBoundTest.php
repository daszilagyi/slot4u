<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\AvailabilityService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The bound that makes availability indexable (SLO-176)
|--------------------------------------------------------------------------
|
| `AvailabilityService::loadBookings()` looks for bookings overlapping a day.
| Written the obvious way — `starts_at < end AND ends_at > start` — there is no
| floor under `starts_at`, so no index can serve it and the database considers
| every booking the tenant ever made. Measured on 55,000 bookings: a full table
| scan of 54,475 rows on the busiest public endpoint (docs/17 §10).
|
| Adding the floor is a two-sided bargain, and both sides are tested here:
|
|   - it must not lose a booking that legitimately overlaps (the one that
|     started last night and is still running — miss it and the slot is offered
|     twice), and
|   - the guarantee it rests on must be ENFORCED, not assumed, which is what
|     the span cap in Admin\BookingRequest is for.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-07 06:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** @return array{0: Tenant, 1: Service, 2: Staff} */
function boundedFixture(): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    // Open around the clock, so the early-morning slots the overnight booking
    // touches genuinely exist in the grid.
    for ($day = 1; $day <= 7; $day++) {
        Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
            ->onDay($day, '00:00', '23:59')->create();
    }

    return [$tenant, $service, $staff];
}

// --- The floor must not lose a real overlap ---

it('still blocks a slot taken by a booking that started the night before', function () {
    // The case the floor could break: the booking begins on the PREVIOUS day and
    // is still running into this one. Miss it and the slot is offered twice —
    // which is the one failure this whole optimisation must not buy.
    [$tenant, $service, $staff] = boundedFixture();

    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'status' => BookingStatus::Confirmed,
        // 23:00 → 01:00 local, straddling midnight.
        'starts_at' => Carbon::parse('2026-09-06 21:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-09-06 23:00:00', 'UTC'),
    ]);

    $slots = app(AvailabilityService::class)->slotsForDay(
        $service->fresh(),
        Carbon::parse('2026-09-07', $tenant->timezone),
    );

    $starts = array_map(fn ($slot): string => $slot->start->toDateTimeString(), $slots);

    // 00:00–01:00 local = 22:00–23:00 UTC on the 6th: covered by the booking.
    expect($starts)->not->toContain('2026-09-06 22:00:00')
        // And the day is not simply empty — the rest of it is still offered.
        ->and($slots)->not->toBeEmpty();
});

it('offers a slot back once the overnight booking has ended', function () {
    [$tenant, $service, $staff] = boundedFixture();

    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'status' => BookingStatus::Confirmed,
        'starts_at' => Carbon::parse('2026-09-06 21:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-09-06 22:00:00', 'UTC'),
    ]);

    $slots = app(AvailabilityService::class)->slotsForDay(
        $service->fresh(),
        Carbon::parse('2026-09-07', $tenant->timezone),
    );

    expect(array_map(fn ($slot): string => $slot->start->toDateTimeString(), $slots))
        ->toContain('2026-09-06 22:00:00');
});

// --- The query really is bounded ---

it('asks the database for a bounded window, not for all of history', function () {
    // The regression this guards: someone simplifies the overlap test back to
    // its obvious form, every page still passes, and the endpoint quietly
    // returns to scanning the whole table. Nothing else in the suite would
    // notice — the results are identical, only the cost changes.
    [$tenant, $service] = boundedFixture();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    app(AvailabilityService::class)->slotsForDay(
        $service->fresh(),
        Carbon::parse('2026-09-07', $tenant->timezone),
    );

    // Identifier quoting differs between the suite's SQLite and production's
    // MariaDB (`"x"` vs `` `x` ``), so the quotes come off before matching —
    // otherwise this passes locally and asserts nothing where it matters.
    $bookingQuery = collect($statements)
        ->map(fn (string $sql): string => str_replace(['`', '"'], '', $sql))
        ->first(fn (string $sql): bool => str_contains($sql, 'from bookings'));

    expect($bookingQuery)->not->toBeNull()
        ->and($bookingQuery)->toContain('starts_at >=');
});

// --- The guarantee is enforced, not assumed ---

it('refuses a booking longer than the span the availability query assumes', function () {
    [$tenant, $service, $staff] = boundedFixture();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    // Three days. Until now `ends_at` only had to be `after:starts_at` — the one
    // duration in the system with no ceiling, while service durations, rental
    // bounds and both buffers are all capped at 1440 minutes.
    $this->actingAs($admin)
        ->from(tenantHost('acme', '/bookings/create'))
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07 10:00',
            'ends_at' => '2026-09-10 10:00',
            'party_size' => 1,
            'guest_name' => 'Teszt Vendég',
            'guest_email' => 'vendeg@example.test',
        ])
        ->assertSessionHasErrors('ends_at');

    expect(Booking::withoutGlobalScopes()->count())->toBe(0);
});

it('accepts a booking that fits inside the span', function () {
    [$tenant, $service, $staff] = boundedFixture();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07 10:00',
            'ends_at' => '2026-09-07 18:00',
            'party_size' => 1,
            'guest_name' => 'Teszt Vendég',
            'guest_email' => 'vendeg@example.test',
        ])
        ->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->count())->toBe(1);
});
