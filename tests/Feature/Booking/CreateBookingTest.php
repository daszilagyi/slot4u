<?php

use App\Actions\Booking\CreateBooking;
use App\Actions\Booking\RescheduleBooking;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A user with the given role in the tenant (has booking.create via role). */
function bookingUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

/** Set the current tenant so BelongsToTenant stamps bookings correctly. */
function withTenant(Tenant $tenant): Tenant
{
    app(TenantManager::class)->set($tenant);

    return $tenant;
}

function durationService(Tenant $tenant, array $overrides = []): Service
{
    return Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'price_minor' => 500000,
        'currency' => 'HUF',
        'requires_staff' => true,
        'requires_approval' => false,
        'online_payment_required' => false,
    ], $overrides));
}

function slotData(Staff $staff, array $overrides = []): array
{
    return array_merge([
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 10:00:00',
        'ends_at' => '2026-09-07 11:00:00',
        'party_size' => 1,
        'source' => 'online',
    ], $overrides);
}

it('creates a time-slot booking and snapshots the price and mode', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $booking = app(CreateBooking::class)($service, slotData($staff));

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->price_minor)->toBe(500000)
        ->and($booking->booking_mode)->toBe(BookingMode::DurationBased)
        ->and($booking->tenant_id)->toBe($tenant->id)
        ->and($booking->code)->not->toBeNull();
});

it('rejects a booking that conflicts with an existing one on the same staff', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 10:30:00',
        'ends_at' => '2026-09-07 11:30:00',
    ]);

    expect(fn () => app(CreateBooking::class)($service, slotData($staff)))
        ->toThrow(SlotUnavailableException::class);

    // The conflicting attempt left no partial row.
    expect(Booking::where('staff_id', $staff->id)->count())->toBe(1);
});

it('allows a non-overlapping booking on the same staff', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 08:00:00',
        'ends_at' => '2026-09-07 09:00:00',
    ]);

    $booking = app(CreateBooking::class)($service, slotData($staff));

    expect($booking->status)->toBe(BookingStatus::Confirmed);
});

it('ignores a canceled booking when checking for conflicts', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Canceled)->create([
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 10:00:00',
        'ends_at' => '2026-09-07 11:00:00',
    ]);

    expect(app(CreateBooking::class)($service, slotData($staff))->status)
        ->toBe(BookingStatus::Confirmed);
});

it('claims event capacity atomically — the last seat goes to exactly one booking', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::EventBased, 'price_minor' => 100000, 'requires_staff' => false,
    ]);
    $event = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id, 'capacity' => 1, 'booked_count' => 0,
    ]);

    $first = app(CreateBooking::class)($service, ['event_id' => $event->id, 'party_size' => 1, 'source' => 'online']);
    expect($first->status)->toBe(BookingStatus::Confirmed)
        ->and($event->fresh()->booked_count)->toBe(1);

    // The event is now full — the next booking must be refused, capacity unchanged.
    expect(fn () => app(CreateBooking::class)($service, ['event_id' => $event->id, 'party_size' => 1, 'source' => 'online']))
        ->toThrow(SlotUnavailableException::class);
    expect($event->fresh()->booked_count)->toBe(1);
});

it('claims the requested party size against the event capacity', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = Service::factory()->forTenant($tenant)->create(['booking_mode' => BookingMode::EventBased, 'requires_staff' => false]);
    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id, 'capacity' => 5, 'booked_count' => 0]);

    app(CreateBooking::class)($service, ['event_id' => $event->id, 'party_size' => 3, 'source' => 'online']);

    expect($event->fresh()->booked_count)->toBe(3);

    // 3 more would exceed the capacity of 5 → refused.
    expect(fn () => app(CreateBooking::class)($service, ['event_id' => $event->id, 'party_size' => 3, 'source' => 'online']))
        ->toThrow(SlotUnavailableException::class);
});

it('starts an approval-required online booking as requested', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant, ['requires_approval' => true]);
    $staff = Staff::factory()->forTenant($tenant)->create();

    expect(app(CreateBooking::class)($service, slotData($staff))->status)
        ->toBe(BookingStatus::Requested);
});

it('starts an online-payment booking as pending_payment', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant, ['online_payment_required' => true]);
    $staff = Staff::factory()->forTenant($tenant)->create();

    expect(app(CreateBooking::class)($service, slotData($staff))->status)
        ->toBe(BookingStatus::PendingPayment);
});

it('confirms an admin booking even when approval is required', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant, ['requires_approval' => true]);
    $staff = Staff::factory()->forTenant($tenant)->create();

    expect(app(CreateBooking::class)($service, slotData($staff, ['source' => 'admin']))->status)
        ->toBe(BookingStatus::Confirmed);
});

it('creates a no_time_slot booking without a slot', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = Service::factory()->forTenant($tenant)->create(['booking_mode' => BookingMode::NoTimeSlot, 'requires_staff' => false]);

    $booking = app(CreateBooking::class)($service, ['party_size' => 1, 'source' => 'online']);

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->starts_at)->toBeNull();
});

it('reschedules by canceling the old booking and creating a new one', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $original = app(CreateBooking::class)($service, slotData($staff));

    $new = app(RescheduleBooking::class)($original, $service, slotData($staff, [
        'starts_at' => '2026-09-07 14:00:00', 'ends_at' => '2026-09-07 15:00:00',
    ]));

    expect($original->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($new->status)->toBe(BookingStatus::Confirmed)
        ->and($new->id)->not->toBe($original->id);
});

it('rolls back a reschedule when the new slot is unavailable (original survives)', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = durationService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $original = app(CreateBooking::class)($service, slotData($staff, ['starts_at' => '2026-09-07 08:00:00', 'ends_at' => '2026-09-07 09:00:00']));
    // A booking blocking the target slot.
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'staff_id' => $staff->id, 'starts_at' => '2026-09-07 10:00:00', 'ends_at' => '2026-09-07 11:00:00',
    ]);

    expect(fn () => app(RescheduleBooking::class)($original, $service, slotData($staff)))
        ->toThrow(SlotUnavailableException::class);

    // The transaction rolled back: the original is still confirmed, not canceled.
    expect($original->fresh()->status)->toBe(BookingStatus::Confirmed);
});

// --- HTTP (admin creation) ---

it('lets an admin create a booking via the endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $service = durationService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $admin = bookingUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T10:00',
            'ends_at' => '2026-09-07T11:00',
            'party_size' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('bookings', [
        'tenant_id' => $tenant->id, 'service_id' => $service->id, 'staff_id' => $staff->id,
        'source' => 'admin', 'status' => 'confirmed',
    ]);
});

// The calendar's quick booking only picks a start (SLO-136); the end is the
// service's own duration, added by the controller.

it('derives the end from the service duration when only a start is posted', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $service = durationService($tenant, ['duration_minutes' => 45]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $admin = bookingUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T10:00',
            'party_size' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('bookings', [
        'service_id' => $service->id,
        'starts_at' => '2026-09-07 10:00:00',
        'ends_at' => '2026-09-07 10:45:00',
    ]);
});

it('keeps the real length when the derived end crosses the spring-forward hour', function () {
    // 2026-03-29 in Budapest: 02:00 local jumps to 03:00. A 60-minute booking
    // starting 01:45 local ends at 03:45 local — wall-clock arithmetic on the client
    // would have produced 02:45, an instant that does not exist that day.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $service = durationService($tenant, ['duration_minutes' => 60]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $admin = bookingUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-03-29T01:45',
            'party_size' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Stored in UTC: 00:45 → 01:45, exactly 60 real minutes (docs/01 §7).
    $this->assertDatabaseHas('bookings', [
        'service_id' => $service->id,
        'starts_at' => '2026-03-29 00:45:00',
        'ends_at' => '2026-03-29 01:45:00',
    ]);
});

it('still demands an end for a service with no fixed duration', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    withTenant($tenant);
    // A variable-length rental (docs/04 §4): nothing to derive the end from.
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental,
        'duration_minutes' => null,
        'requires_staff' => false,
        'requires_room' => true,
    ]);
    $room = Room::factory()->forTenant($tenant)->create();
    $admin = bookingUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id,
            'room_id' => $room->id,
            'starts_at' => '2026-09-07T10:00',
            'party_size' => 1,
        ])
        ->assertSessionHasErrors('ends_at');
});

it('returns a 422 when the admin books an already-taken slot', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $service = durationService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $admin = bookingUser($tenant, Role::TenantAdmin);
    withTenant($tenant);
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'staff_id' => $staff->id, 'starts_at' => '2026-09-07 10:00:00', 'ends_at' => '2026-09-07 11:00:00',
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id, 'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T10:30', 'ends_at' => '2026-09-07T11:30', 'party_size' => 1,
        ])
        ->assertSessionHasErrors('booking');
});

it('rejects a service from another tenant in the booking endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $admin = bookingUser($tenant, Role::TenantAdmin);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignService = durationService($other);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $foreignService->id, 'party_size' => 1,
            'starts_at' => '2026-09-07T10:00', 'ends_at' => '2026-09-07T11:00',
        ])
        ->assertSessionHasErrors('service_id');
});

it('forbids a user without booking.create (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    // No role → no booking.create.

    $this->actingAs($user)
        ->post(tenantHost('acme', '/bookings'), ['service_id' => 1, 'party_size' => 1])
        ->assertForbidden();

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('does not falsely conflict two staff-less bookings on different rooms', function () {
    $tenant = withTenant(Tenant::factory()->active()->create());
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental, 'duration_minutes' => 60,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_staff' => false, 'requires_room' => true,
    ]);
    $roomA = Room::factory()->forTenant($tenant)->create();
    $roomB = Room::factory()->forTenant($tenant)->create();
    $slot = ['starts_at' => '2026-09-07 10:00:00', 'ends_at' => '2026-09-07 11:00:00', 'party_size' => 1, 'source' => 'online'];

    app(CreateBooking::class)($service, array_merge($slot, ['room_id' => $roomA->id]));
    // Same window, DIFFERENT room — must NOT be a false conflict.
    $b = app(CreateBooking::class)($service, array_merge($slot, ['room_id' => $roomB->id]));
    expect($b->status)->toBe(BookingStatus::Confirmed);

    // Same room, same window — a real conflict.
    expect(fn () => app(CreateBooking::class)($service, array_merge($slot, ['room_id' => $roomA->id])))
        ->toThrow(SlotUnavailableException::class);
});

it('rejects a staff member from another tenant in the booking endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $service = durationService($tenant);
    $admin = bookingUser($tenant, Role::TenantAdmin);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignStaff = Staff::factory()->forTenant($other)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), [
            'service_id' => $service->id, 'staff_id' => $foreignStaff->id, 'party_size' => 1,
            'starts_at' => '2026-09-07T10:00', 'ends_at' => '2026-09-07T11:00',
        ])
        ->assertSessionHasErrors('staff_id');
});
