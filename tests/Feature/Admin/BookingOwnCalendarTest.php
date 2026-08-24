<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Requests\Admin\ProposeBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The employee "saját naptárba" scope on the write side of a booking (docs/03
 * matrix, SLO-178).
 *
 * The staff picker was already narrowed to the actor's own calendars, but a
 * picker is decoration: the id arrives in the request body, and nothing on the
 * server said it had to be theirs. An employee could book into a colleague's
 * calendar, and could move their own booking into one — after which it fell out
 * of their own list and they could not even cancel it back.
 *
 * tenantHost() lives in tests/Pest.php.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A user holding the given role in the given tenant. */
function ownCalUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

/**
 * A tenant with an employee linked to their own staff record, a colleague, and a
 * plain duration-based service to book.
 *
 * @return array{0: Tenant, 1: User, 2: Staff, 3: Staff, 4: Service}
 */
function ownCalFixture(): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $employee = ownCalUser($tenant, Role::Employee);
    $mine = Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $theirs = Staff::factory()->forTenant($tenant)->create();
    $service = Service::factory()->forTenant($tenant)->create(['duration_minutes' => 60]);

    return [$tenant, $employee, $mine, $theirs, $service];
}

/** Minimal valid admin booking payload for a duration-based service. */
function ownCalPayload(Service $service, ?int $staffId, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'staff_id' => $staffId,
        'starts_at' => '2026-09-01 10:00',
        'ends_at' => '2026-09-01 11:00',
        'party_size' => 1,
    ], $overrides);
}

// ------------------------------------------------------------------ create

it('rejects an employee booking into a colleague calendar', function () {
    [$tenant, $employee, $mine, $theirs, $service] = ownCalFixture();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', '/bookings'), ownCalPayload($service, $theirs->id))
        ->assertSessionHasErrors('staff_id');

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('rejects an employee booking a room rental, which carries no calendar', function () {
    [$tenant, $employee] = ownCalFixture();
    $rental = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental,
        'duration_minutes' => 60,
    ]);
    $room = Room::factory()->forTenant($tenant)->create();
    app(TenantManager::class)->forget();

    // ⚠️ The one hole a missing staff_id actually opens. A duration-based service
    // already demands a resource, so a staffless booking there fails on the old
    // rule and proves nothing. A room rental takes room_id and leaves staff_id
    // optional — so before this, an employee could create a booking that fell
    // outside their own list the moment it was saved: invisible to them, not
    // cancellable, not findable.
    $this->actingAs($employee)
        ->post(tenantHost('acme', '/bookings'), ownCalPayload($rental, null, ['room_id' => $room->id]))
        ->assertSessionHasErrors('staff_id');

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('still rejects a staffless booking on a service that demands a resource', function () {
    [$tenant, $employee, $mine, $theirs, $service] = ownCalFixture();
    app(TenantManager::class)->forget();

    // Belt and braces: the older `resource_required` rule covered this case, and
    // the new one must not have replaced it with something weaker.
    $this->actingAs($employee)
        ->post(tenantHost('acme', '/bookings'), ownCalPayload($service, null))
        ->assertSessionHasErrors('staff_id');

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('lets an employee book into their own calendar', function () {
    [$tenant, $employee, $mine, $theirs, $service] = ownCalFixture();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', '/bookings'), ownCalPayload($service, $mine->id))
        ->assertSessionHasNoErrors();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect((int) $booking->staff_id)->toBe($mine->id);
});

it('leaves a manager free to book any calendar', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $manager = ownCalUser($tenant, Role::Manager);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service = Service::factory()->forTenant($tenant)->create(['duration_minutes' => 60]);
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->post(tenantHost('acme', '/bookings'), ownCalPayload($service, $staff->id))
        ->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('widens an employee given the unrestricted booking scope', function () {
    [$tenant, $employee, $mine, $theirs, $service] = ownCalFixture();

    // BookingVisibility's wide side is the tenant-admin/manager role, so grant the
    // manager role rather than a code — this asserts the scope hook is consulted,
    // not that a particular actor is hardcoded.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee->assignRole(Role::Manager->value);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', '/bookings'), ownCalPayload($service, $theirs->id))
        ->assertSessionHasNoErrors();
});

// ------------------------------------------------------------------- move

it('rejects an employee moving their own booking into a colleague calendar', function () {
    [$tenant, $employee, $mine, $theirs, $service] = ownCalFixture();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $mine->id,
        'status' => BookingStatus::Confirmed->value,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);
    app(TenantManager::class)->forget();

    // The booking binds fine — it IS theirs. Without the check on the SUBMITTED
    // resource, the move would push it out of their own list for good.
    $this->actingAs($employee)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-02 10:00',
            'staff_id' => $theirs->id,
        ])
        ->assertSessionHasErrors('staff_id');

    expect((int) $booking->refresh()->staff_id)->toBe($mine->id);
});

it('lets an employee move their own booking within their own calendar', function () {
    [$tenant, $employee, $mine, $theirs, $service] = ownCalFixture();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $mine->id,
        'status' => BookingStatus::Confirmed->value,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);
    app(TenantManager::class)->forget();

    // No staff_id: a calendar drag submits only the new start, and the booking
    // keeps the resource it already has. That resource is already theirs, so the
    // optional shape must not block it.
    $this->actingAs($employee)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-02 10:00',
        ])
        ->assertSessionHasNoErrors();
});

it('rejects an employee proposing an alternative in a colleague calendar', function () {
    [$tenant, $employee, $mine, $theirs, $service] = ownCalFixture();

    // booking.approve is not in the employee's seeded grant (docs/03), but a
    // tenant can hand it out individually — the calendar scope has to hold then.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee->givePermissionTo(Permission::BookingApprove->value);

    $request = new ProposeBookingRequest;
    $request->setUserResolver(fn () => $employee);
    $request->merge(['staff_id' => $theirs->id]);

    $validator = validator([], []);
    $request->withValidator($validator);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->has('staff_id'))->toBeTrue();
});
