<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Customer creation assigns the `customer` role (FindOrCreateCustomer); feature
    // defaults (the mode is feature-independent, but the plan must exist) come from
    // the plan_features table seeded by BasePlanSeeder.
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * A free-range resource_rental (duration_minutes null, min 30 / max 120) on a
 * UTC tenant with a 30-min grid and a room open Mon 09:00–17:00 — so
 * 2026-09-07 09:00Z is a real min-length grid start a guest can extend.
 *
 * @return array{0: Tenant, 1: Service, 2: Room}
 */
function rentalDurationService(array $settingsOverrides = []): array
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'timezone' => 'UTC',
        'settings' => ['slot_interval_minutes' => 30],
    ]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental,
        'duration_minutes' => null,
        'settings' => array_merge(['min_duration_minutes' => 30, 'max_duration_minutes' => 120], $settingsOverrides),
        'active' => true,
    ]);
    $room = Room::factory()->forTenant($tenant)->create();
    $service->rooms()->attach($room->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($room)
        ->onDay(Carbon::parse('2026-09-07')->isoWeekday(), '09:00', '17:00')
        ->create();

    return [$tenant, $service, $room];
}

/**
 * @return array<string, mixed>
 */
function rentalPayload(Service $service, Room $room, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'room_id' => $room->id,
        'starts_at' => '2026-09-07T09:00:00Z',
        'ends_at' => '2026-09-07T09:30:00Z',
        'duration_minutes' => 30,
        'name' => 'Teszt Vendég',
        'email' => 'guest@example.test',
        'phone' => '+3611234567',
    ], $overrides);
}

function rentalBookings(Tenant $tenant)
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id);
}

it('books the minimum duration with the correct end time', function () {
    [$tenant, $service, $room] = rentalDurationService();

    $this->post(tenantHost('acme', '/book'), rentalPayload($service, $room, [
        'duration_minutes' => 30,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $booking = rentalBookings($tenant)->first();
    expect($booking)->not->toBeNull()
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->starts_at->toIso8601String())->toBe('2026-09-07T09:00:00+00:00')
        ->and($booking->ends_at->toIso8601String())->toBe('2026-09-07T09:30:00+00:00');
});

it('books the maximum duration extending the end time', function () {
    [$tenant, $service, $room] = rentalDurationService();

    // ends_at is a shape field only; store() derives the end from the matched range.
    $this->post(tenantHost('acme', '/book'), rentalPayload($service, $room, [
        'duration_minutes' => 120,
        'ends_at' => '2026-09-07T11:00:00Z',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $booking = rentalBookings($tenant)->first();
    expect($booking)->not->toBeNull()
        ->and($booking->ends_at->toIso8601String())->toBe('2026-09-07T11:00:00+00:00');
});

it('rejects a duration below the minimum bound', function () {
    [$tenant, $service, $room] = rentalDurationService();

    $this->post(tenantHost('acme', '/book'), rentalPayload($service, $room, [
        'duration_minutes' => 15,
    ]))->assertSessionHasErrors('duration_minutes');

    expect(rentalBookings($tenant)->count())->toBe(0);
});

it('rejects a duration above the maximum bound', function () {
    [$tenant, $service, $room] = rentalDurationService();

    $this->post(tenantHost('acme', '/book'), rentalPayload($service, $room, [
        'duration_minutes' => 180,
        'ends_at' => '2026-09-07T12:00:00Z',
    ]))->assertSessionHasErrors('duration_minutes');

    expect(rentalBookings($tenant)->count())->toBe(0);
});

it('rejects a chosen range that overlaps an existing booking even when the min slot is free', function () {
    [$tenant, $service, $room] = rentalDurationService();

    // A booking at 10:00–10:30 leaves the 09:00–09:30 min slot free, but a 120-min
    // range from 09:00 (→ 11:00) runs into it.
    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'room_id' => $room->id,
        'booking_mode' => BookingMode::ResourceRental,
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-07 10:00:00',
        'ends_at' => '2026-09-07 10:30:00',
    ]);

    $this->post(tenantHost('acme', '/book'), rentalPayload($service, $room, [
        'duration_minutes' => 120,
        'ends_at' => '2026-09-07T11:00:00Z',
    ]))->assertSessionHasErrors('booking');

    // Only the pre-existing booking remains.
    expect(rentalBookings($tenant)->count())->toBe(1);
});

it('rejects an off-grid start even with an allowed duration', function () {
    [$tenant, $service, $room] = rentalDurationService();

    // 09:15 is not a 30-min grid start; the duration itself (30) is allowed, so the
    // rejection comes from the slot re-validation, not the duration bounds.
    $this->post(tenantHost('acme', '/book'), rentalPayload($service, $room, [
        'starts_at' => '2026-09-07T09:15:00Z',
        'ends_at' => '2026-09-07T09:45:00Z',
        'duration_minutes' => 30,
    ]))->assertSessionHasErrors('booking');

    expect(rentalBookings($tenant)->count())->toBe(0);
});

it('rejects a room_id from another tenant', function () {
    [$tenant, $service, $room] = rentalDurationService();

    // A room owned by a different tenant must never book on acme's subdomain: the
    // tenant-anchored `exists` rule rejects it, and matchRentalSlot's
    // service->rooms->contains guard would also fail it (defense-in-depth).
    $otherTenant = Tenant::factory()->active()->create(['slug' => 'other']);
    $otherRoom = Room::factory()->forTenant($otherTenant)->create();

    $this->post(tenantHost('acme', '/book'), rentalPayload($service, $room, [
        'room_id' => $otherRoom->id,
        'duration_minutes' => 60,
    ]))->assertSessionHasErrors('room_id');

    expect(rentalBookings($tenant)->count())->toBe(0);
});
