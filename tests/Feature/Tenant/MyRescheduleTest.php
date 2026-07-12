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
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    // 2026-09-01 is a Tuesday; the schedules below open Monday, so 2026-09-07
    // (Monday) 09:00 local is a real, future, bookable slot.
    Carbon::setTestNow('2026-09-01 00:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * Active tenant (24h cancellation deadline) with a duration_based service (60 min),
 * one assignable staff member, and a Monday 09:00–17:00 band — so 2026-09-07 09:00
 * local (= 07:00Z, Europe/Budapest) is a real slot the reschedule can move to.
 *
 * @return array{0: Tenant, 1: Service, 2: Staff, 3: User}
 */
function rescheduleSetup(array $tenantSettings = []): array
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'settings' => array_merge(['cancellation_deadline_hours' => 24], $tenantSettings),
    ]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(Carbon::parse('2026-09-07')->isoWeekday(), '09:00', '17:00')
        ->create();

    $me = User::factory()->create(['tenant_id' => $tenant->id]);
    $me->assignRole(Role::Customer->value);

    return [$tenant, $service, $staff, $me];
}

function rescheduleCustomer(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

function tenantBookingsFor(Tenant $tenant)
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id);
}

it('renders the reschedule form for the customer\'s own time-slot booking', function () {
    [$tenant, $service, $staff, $me] = rescheduleSetup();
    $booking = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-05 10:00:00', '2026-09-05 11:00:00')
        ->create(['customer_id' => $me->id, 'service_id' => $service->id, 'staff_id' => $staff->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', "/my/bookings/{$booking->id}/reschedule"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/My/Reschedule')
            ->where('booking.code', $booking->code)
            ->has('slots')
            ->has('week', 7));
});

it('reschedules the customer\'s own booking to a free slot outside the deadline', function () {
    [$tenant, $service, $staff, $me] = rescheduleSetup();
    // Original is 4 days out → well outside the 24h deadline, so the cancel leg is allowed.
    $original = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-05 10:00:00', '2026-09-05 11:00:00')
        ->create(['customer_id' => $me->id, 'service_id' => $service->id, 'staff_id' => $staff->id]);

    $this->actingAs($me)
        ->post(tenantHost('acme', "/my/bookings/{$original->id}/reschedule"), [
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T07:00:00Z',
            'ends_at' => '2026-09-07T08:00:00Z',
        ])
        ->assertRedirect(tenantHost('acme', '/my/bookings'))
        ->assertSessionHasNoErrors();

    // The old booking is canceled; a new confirmed booking exists at the new time,
    // for the same customer.
    expect($original->fresh()->status)->toBe(BookingStatus::Canceled);

    $new = tenantBookingsFor($tenant)
        ->where('status', BookingStatus::Confirmed)
        ->where('customer_id', $me->id)
        ->first();
    expect($new)->not->toBeNull()
        ->and($new->id)->not->toBe($original->id)
        ->and($new->starts_at->toIso8601String())->toBe('2026-09-07T07:00:00+00:00');
});

it('refuses to reschedule inside the cancellation deadline and rolls back', function () {
    [$tenant, $service, $staff, $me] = rescheduleSetup();
    // Original starts in 10h → inside the 24h deadline.
    $original = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-01 10:00:00', '2026-09-01 11:00:00')
        ->create(['customer_id' => $me->id, 'service_id' => $service->id, 'staff_id' => $staff->id]);

    $this->actingAs($me)
        ->from(tenantHost('acme', "/my/bookings/{$original->id}/reschedule"))
        ->post(tenantHost('acme', "/my/bookings/{$original->id}/reschedule"), [
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T07:00:00Z',
            'ends_at' => '2026-09-07T08:00:00Z',
        ])
        ->assertSessionHasErrors('cancel');

    // The original is untouched and no new booking was created (transaction rolled back).
    expect($original->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(tenantBookingsFor($tenant)->count())->toBe(1);
});

it('rejects a reschedule to an already-taken slot, leaving the original unchanged', function () {
    [$tenant, $service, $staff, $me] = rescheduleSetup();
    $original = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-05 10:00:00', '2026-09-05 11:00:00')
        ->create(['customer_id' => $me->id, 'service_id' => $service->id, 'staff_id' => $staff->id]);

    // Another confirmed booking already occupies the target 09:00 slot for this staff.
    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'booking_mode' => BookingMode::DurationBased,
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-07 07:00:00',
        'ends_at' => '2026-09-07 08:00:00',
    ]);

    $this->actingAs($me)
        ->from(tenantHost('acme', "/my/bookings/{$original->id}/reschedule"))
        ->post(tenantHost('acme', "/my/bookings/{$original->id}/reschedule"), [
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T07:00:00Z',
            'ends_at' => '2026-09-07T08:00:00Z',
        ])
        ->assertSessionHasErrors('booking');

    expect($original->fresh()->status)->toBe(BookingStatus::Confirmed)
        // Only the original + the occupying booking exist — nothing new was created.
        ->and(tenantBookingsFor($tenant)->count())->toBe(2);
});

it('404s the reschedule of another customer\'s booking (GET form and POST)', function () {
    [$tenant, $service, $staff, $me] = rescheduleSetup();
    $other = rescheduleCustomer($tenant);
    $foreign = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-05 10:00:00', '2026-09-05 11:00:00')
        ->create(['customer_id' => $other->id, 'service_id' => $service->id, 'staff_id' => $staff->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', "/my/bookings/{$foreign->id}/reschedule"))
        ->assertNotFound();

    $this->actingAs($me)
        ->post(tenantHost('acme', "/my/bookings/{$foreign->id}/reschedule"), [
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-07T07:00:00Z',
            'ends_at' => '2026-09-07T08:00:00Z',
        ])
        ->assertNotFound();

    expect($foreign->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('404s the reschedule of a non-time-slot booking', function () {
    [$tenant, , , $me] = rescheduleSetup();
    $orderService = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::NoTimeSlot,
        'duration_minutes' => null,
        'active' => true,
    ]);
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $me->id,
        'service_id' => $orderService->id,
        'booking_mode' => BookingMode::NoTimeSlot,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $this->actingAs($me)
        ->get(tenantHost('acme', "/my/bookings/{$booking->id}/reschedule"))
        ->assertNotFound();

    $this->actingAs($me)
        ->post(tenantHost('acme', "/my/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-07T07:00:00Z',
            'ends_at' => '2026-09-07T08:00:00Z',
        ])
        ->assertNotFound();
});

it('reschedules a free-range rental with a chosen duration and rejects a below-min one', function () {
    // UTC tenant, 30-min grid, 24h deadline; a free-range rental (30–120 min) on a
    // room open Monday 09:00–17:00.
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'timezone' => 'UTC',
        'settings' => ['slot_interval_minutes' => 30, 'cancellation_deadline_hours' => 24],
    ]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

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

    $me = rescheduleCustomer($tenant);
    $original = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $me->id,
        'service_id' => $service->id,
        'room_id' => $room->id,
        'booking_mode' => BookingMode::ResourceRental,
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-05 09:00:00',
        'ends_at' => '2026-09-05 10:00:00',
    ]);

    // A below-min duration is rejected by the form request bounds check.
    $this->actingAs($me)
        ->from(tenantHost('acme', "/my/bookings/{$original->id}/reschedule"))
        ->post(tenantHost('acme', "/my/bookings/{$original->id}/reschedule"), [
            'room_id' => $room->id,
            'starts_at' => '2026-09-07T09:00:00Z',
            'ends_at' => '2026-09-07T09:15:00Z',
            'duration_minutes' => 15,
        ])
        ->assertSessionHasErrors('duration_minutes');
    expect($original->fresh()->status)->toBe(BookingStatus::Confirmed);

    // A valid 60-min duration reschedules successfully, extending the end.
    $this->actingAs($me)
        ->post(tenantHost('acme', "/my/bookings/{$original->id}/reschedule"), [
            'room_id' => $room->id,
            'starts_at' => '2026-09-07T09:00:00Z',
            'ends_at' => '2026-09-07T10:00:00Z',
            'duration_minutes' => 60,
        ])
        ->assertRedirect(tenantHost('acme', '/my/bookings'))
        ->assertSessionHasNoErrors();

    expect($original->fresh()->status)->toBe(BookingStatus::Canceled);
    $new = tenantBookingsFor($tenant)
        ->where('status', BookingStatus::Confirmed)
        ->where('customer_id', $me->id)
        ->first();
    expect($new)->not->toBeNull()
        ->and($new->starts_at->toIso8601String())->toBe('2026-09-07T09:00:00+00:00')
        ->and($new->ends_at->toIso8601String())->toBe('2026-09-07T10:00:00+00:00');
});
