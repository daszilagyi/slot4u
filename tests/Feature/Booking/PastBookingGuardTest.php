<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Room;
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
use Spatie\Permission\PermissionRegistrar;

/**
 * A customer cannot book a time that has already passed — an admin can (SLO-207).
 *
 * The two halves belong in one file because the rule is the *difference* between
 * them, not either one alone. A visitor picking a slot is choosing when to turn
 * up, and a time that has gone is not a choice; an admin is recording what
 * already happened — this morning's walk-in, yesterday's phone booking — and
 * refusing that would make the diary unable to describe the day it just had.
 *
 * Every test freezes the clock at 13:00 on the band's own Monday, so "past" and
 * "future" are halves of the same working day rather than different dates. The
 * 09:00 slot below is the same slot throughout: refused for the visitor,
 * accepted for the admin.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    // Monday 2026-09-07, midday — the band is 09:00–17:00, so the morning is
    // behind us and the afternoon is not.
    Carbon::setTestNow('2026-09-07 13:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * A 60-minute duration_based service on a UTC tenant, one staff member, open
 * Monday 09:00–17:00 on a 30-minute grid.
 *
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function pastGuardService(): array
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'timezone' => 'UTC',
        'settings' => ['slot_interval_minutes' => 30],
    ]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_staff' => true,
        'active' => true,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(Carbon::parse('2026-09-07')->isoWeekday(), '09:00', '17:00')
        ->create();

    return [$tenant, $service, $staff];
}

/** @return array<string, mixed> */
function pastGuardPayload(Service $service, Staff $staff, string $start, string $end): array
{
    return [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'name' => 'Teszt Vendég',
        'email' => 'vendeg@example.test',
        'phone' => '+3611234567',
    ];
}

// --- What the picker offers -------------------------------------------------

it('stops offering the slots that have already started today', function () {
    [, $service] = pastGuardService();

    $slots = app(AvailabilityService::class)->slotsForDay($service, Carbon::parse('2026-09-07', 'UTC'));

    $times = array_map(fn ($slot) => $slot->start->format('H:i'), $slots);

    // ⚠️ The bug this closes was visible without crafting anything: at 13:00 the
    // page still listed 09:00 as bookable. The band runs to 17:00 and the service
    // is an hour long, so 16:00 is the last start that fits.
    expect($times)->toBe(['13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'])
        ->and($times)->not->toContain('09:00');
});

it('keeps a slot that starts exactly now', function () {
    [, $service] = pastGuardService();

    Carbon::setTestNow('2026-09-07 13:00:00');

    $slots = app(AvailabilityService::class)->slotsForDay($service, Carbon::parse('2026-09-07', 'UTC'));

    // Not yet started is not started. Rounding this edge the other way would put
    // the boundary at the mercy of which side of a millisecond a request landed.
    expect(array_map(fn ($slot) => $slot->start->format('H:i'), $slots))->toContain('13:00');
});

it('offers a full future day exactly as before', function () {
    [, $service] = pastGuardService();

    // Nothing about a later day changes — the guard trims the present, it does
    // not narrow the grid.
    $slots = app(AvailabilityService::class)->slotsForDay($service, Carbon::parse('2026-09-14', 'UTC'));

    expect($slots)->toHaveCount(15)
        ->and($slots[0]->start->format('H:i'))->toBe('09:00');
});

// --- What a visitor may submit ---------------------------------------------

it('⚠️ refuses a public booking crafted for a slot that has passed', function () {
    [, $service, $staff] = pastGuardService();

    // The grid is only what the page draws; this is a POST straight at the
    // endpoint for a time the page no longer shows.
    $this->post(
        tenantHost('acme', '/book'),
        pastGuardPayload($service, $staff, '2026-09-07T09:00:00Z', '2026-09-07T10:00:00Z'),
    )->assertSessionHasErrors('booking');

    expect(Booking::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('still accepts a public booking later the same day', function () {
    [, $service, $staff] = pastGuardService();

    // The other half of the previous test: the refusal is about the time, not
    // about the endpoint having stopped working.
    $this->post(
        tenantHost('acme', '/book'),
        pastGuardPayload($service, $staff, '2026-09-07T14:00:00Z', '2026-09-07T15:00:00Z'),
    )->assertRedirect()->assertSessionHasNoErrors();

    expect(Booking::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('⚠️ refuses a free-range rental that starts in the past', function () {
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'timezone' => 'UTC',
        'settings' => ['slot_interval_minutes' => 30],
    ]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental,
        'duration_minutes' => null,
        'settings' => ['min_duration_minutes' => 30, 'max_duration_minutes' => 120],
        'active' => true,
    ]);
    $room = Room::factory()->forTenant($tenant)->create();
    $service->rooms()->attach($room->id);
    Schedule::factory()->forTenant($tenant)->forSchedulable($room)
        ->onDay(Carbon::parse('2026-09-07')->isoWeekday(), '09:00', '17:00')
        ->create();

    // ⚠️ This path builds its own slot rather than picking one off the grid, so
    // it does not inherit the filter — without its own check the guard would
    // have a rental-shaped hole in it.
    $slot = app(AvailabilityService::class)->matchRentalSlot(
        $service,
        Carbon::parse('2026-09-07 09:00:00', 'UTC'),
        60,
        $room->id,
    );

    expect($slot)->toBeNull();

    // And the same start later in the day still resolves, so the null above is
    // the time being refused rather than the scenario being broken.
    expect(app(AvailabilityService::class)->matchRentalSlot(
        $service,
        Carbon::parse('2026-09-07 14:00:00', 'UTC'),
        60,
        $room->id,
    ))->not->toBeNull();
});

it('⚠️ refuses to let a customer reschedule their own booking into the past', function () {
    [$tenant, $service, $staff] = pastGuardService();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $customer = User::factory()->create(['tenant_id' => $tenant->id]);
    $customer->assignRole(Role::Customer->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-14 10:00:00',
        'ends_at' => '2026-09-14 11:00:00',
    ]);
    app(TenantManager::class)->forget();

    // Moving a booking is picking a slot too, and it runs through the same
    // matcher — so the rule has to hold here without being written twice.
    $this->actingAs($customer)
        ->post(tenantHost('acme', '/my/bookings/'.$booking->id.'/reschedule'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T09:00:00Z',
            'ends_at' => '2026-09-07T10:00:00Z',
        ])
        ->assertSessionHasErrors('booking');

    expect($booking->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-14 10:00');
});

// --- What an admin may record ----------------------------------------------

it('⚠️ lets a tenant admin record a booking for a time that has passed', function () {
    [$tenant, $service, $staff] = pastGuardService();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    // ⚠️ THE test of this issue. The same 09:00 slot the visitor was refused two
    // tests ago, entered by the admin at 13:00 — this is Daniel's decision made
    // executable: the walk-in who turned up this morning has to be enterable, or
    // the diary cannot describe the day it just had.
    //
    // It is also the guard on the guard: this fails the moment the past-slot
    // filter leaks from AvailabilityService into the admin path.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T09:00',
            'ends_at' => '2026-09-07T10:00',
            'party_size' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('bookings', [
        'tenant_id' => $tenant->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'source' => 'admin',
    ]);
});

it('⚠️ lets a tenant admin record a booking for yesterday', function () {
    [$tenant, $service, $staff] = pastGuardService();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    // Not just earlier today: "visszamenőleg is időben" means a whole day back
    // too, and an admin booking is not bound to the published schedule at all —
    // 2026-09-06 is a Sunday, outside the Monday band.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-06T09:00',
            'ends_at' => '2026-09-06T10:00',
            'party_size' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('bookings', [
        'tenant_id' => $tenant->id,
        'starts_at' => '2026-09-06 09:00:00',
        'source' => 'admin',
    ]);
});
