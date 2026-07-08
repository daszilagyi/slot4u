<?php

use App\Enums\BookingMode;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A duration_based service with one staff member and a weekly band on the given
 * date's weekday, so AvailabilityService produces slots.
 *
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function bookableService(string $onDate, array $serviceOverrides = []): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
    ], $serviceOverrides));
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(Carbon::parse($onDate)->isoWeekday(), '09:00', '17:00')
        ->create();

    return [$tenant, $service, $staff];
}

/**
 * A resource_rental service with one room (in a named location) and a weekly band
 * on the given date's weekday.
 *
 * @return array{0: Tenant, 1: Service, 2: Room, 3: Location}
 */
function bookableRoomService(string $onDate): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $location = Location::factory()->forTenant($tenant)->create(['name' => 'Belváros']);
    $room = Room::factory()->forTenant($tenant)->forLocation($location)->create(['name' => 'Terem A']);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::ResourceRental)
        ->create(['duration_minutes' => 60, 'active' => true]);
    $service->rooms()->attach($room->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($room)
        ->onDay(Carbon::parse($onDate)->isoWeekday(), '09:00', '17:00')
        ->create();

    return [$tenant, $service, $room, $location];
}

it('renders the booking page with slots for a scheduled day', function () {
    [, $service] = bookableService('2026-09-07'); // Monday

    $this->get(tenantHost('acme', "/book?service={$service->id}&date=2026-09-07"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Book')
            ->where('service.id', $service->id)
            ->where('selected_date', '2026-09-07')
            ->has('week', 7)
            ->where('slots.0.time', '09:00'));
});

it('narrows slots to a specific staff filter', function () {
    [, $service, $staff] = bookableService('2026-09-07');

    $this->get(tenantHost('acme', "/book?service={$service->id}&date=2026-09-07&staff={$staff->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('slots.0.staff_id', $staff->id)
            ->where('filters.staff', $staff->id));
});

it('returns no slots for a day without a schedule band', function () {
    [, $service] = bookableService('2026-09-07'); // band only on Mondays

    // 2026-09-08 is a Tuesday — no band.
    $this->get(tenantHost('acme', "/book?service={$service->id}&date=2026-09-08"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('slots', 0));
});

it('renders room slots and builds room + location options for a resource_rental service', function () {
    [, $service, $room] = bookableRoomService('2026-09-07');

    $this->get(tenantHost('acme', "/book?service={$service->id}&date=2026-09-07"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('service.booking_mode', 'resource_rental')
            ->where('slots.0.room_id', $room->id)
            ->where('slots.0.time', '09:00')
            ->where('room_options.0.id', $room->id)
            ->where('location_options.0.name', 'Belváros'));
});

it('narrows resource_rental slots to a specific room filter', function () {
    [, $service, $room] = bookableRoomService('2026-09-07');

    $this->get(tenantHost('acme', "/book?service={$service->id}&date=2026-09-07&room={$room->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.room', $room->id)
            ->where('slots.0.room_id', $room->id));
});

it('clamps a past requested date to today', function () {
    $today = Carbon::now('Europe/Budapest')->toDateString();
    [, $service] = bookableService($today);

    $this->get(tenantHost('acme', "/book?service={$service->id}&date=2020-01-01"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selected_date', $today));
});

it('shows an info panel (no slots) for an event_based service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->eventBased()->create(['active' => true]);

    $this->get(tenantHost('acme', "/book?service={$service->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Book')
            ->where('service.booking_mode', 'event_based')
            ->missing('slots'));
});

it('renders an empty state when the tenant has no services', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/book'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Book')
            ->where('service', null)
            ->has('services', 0));
});

it('falls back to an own service and never selects another tenant\'s service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $mine = Service::factory()->forTenant($tenant)->create([
        'name' => 'Sajátom',
        'booking_mode' => BookingMode::DurationBased,
        'active' => true,
    ]);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = Service::factory()->forTenant($other)->create(['active' => true]);

    // Requesting the foreign id → not found in this tenant → falls back to own.
    $this->get(tenantHost('acme', "/book?service={$foreign->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('service.id', $mine->id));
});
