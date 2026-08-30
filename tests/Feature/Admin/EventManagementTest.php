<?php

use App\Enums\BookingMode;
use App\Enums\EventStatus;
use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Event;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A user with the given role in the tenant. */
function eventUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

/** An event_based service in the tenant. */
function eventService(Tenant $tenant): Service
{
    return Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::EventBased,
        'capacity' => 20,
        'requires_staff' => false,
    ]);
}

/** Minimal valid event payload (datetime-local strings, tenant-tz). */
function eventPayload(Service $service, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'staff_id' => null,
        'room_id' => null,
        'starts_at' => '2026-09-01T10:00',
        'ends_at' => '2026-09-01T11:00',
        'capacity' => 10,
        'waitlist_enabled' => false,
        'repeat_weekly' => false,
    ], $overrides);
}

it('lists events and event_based services', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    Event::factory()->forTenant($tenant)->create(['service_id' => $service->id]);
    // A non-event_based service must not appear in the picker.
    Service::factory()->forTenant($tenant)->create(['booking_mode' => BookingMode::DurationBased]);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/events'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Events/Index')
            ->has('events', 1)
            ->has('services', 1)
            ->where('timezone', 'Europe/Budapest'));
});

it('creates a single event', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, ['capacity' => 12]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Event::where('service_id', $service->id)->count())->toBe(1);
    $event = Event::firstOrFail();
    expect($event->capacity)->toBe(12)
        ->and($event->series_id)->toBeNull();
});

it('stores the event time as UTC converted from the tenant timezone', function () {
    // 10:00 Europe/Budapest on 2026-09-01 (CEST, UTC+2) → 08:00 UTC (docs/01 §7).
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service))
        ->assertRedirect();

    $event = Event::firstOrFail();
    expect($event->starts_at->toDateTimeString())->toBe('2026-09-01 08:00:00')
        ->and($event->ends_at->toDateTimeString())->toBe('2026-09-01 09:00:00');
});

it('generates a weekly series until the end date', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'repeat_weekly' => true,
            'repeat_until' => '2026-09-22',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Sep 1, 8, 15, 22 = 4 weekly occurrences, all in one series.
    $events = Event::where('service_id', $service->id)->orderBy('starts_at')->get();
    expect($events)->toHaveCount(4)
        ->and($events->pluck('series_id')->unique())->toHaveCount(1)
        ->and($events->first()->series_id)->not->toBeNull();
});

it('rejects an event for a non-event_based service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = Service::factory()->forTenant($tenant)->create(['booking_mode' => BookingMode::DurationBased]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service))
        ->assertSessionHasErrors('service_id');
});

it('rejects an event that clashes with another on the same staff', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    // Existing event 08:00–09:00 UTC (= 10:00 local) for the staff.
    Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-01T10:30',
            'ends_at' => '2026-09-01T11:30',
        ]))
        ->assertSessionHasErrors('starts_at');
});

it('allows an event on the same staff at a non-overlapping time', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-01T12:00',
            'ends_at' => '2026-09-01T13:00',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('forbids lowering capacity below the current bookings', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $event = Event::factory()->forTenant($tenant)->withBookings(5)
        ->create(['service_id' => $service->id]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/events/{$event->id}"), eventPayload($service, [
            'capacity' => 3,
            'scope' => 'this',
        ]))
        ->assertSessionHasErrors('capacity');
});

it('allows raising capacity above the current bookings', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $event = Event::factory()->forTenant($tenant)->withBookings(5)
        ->create(['service_id' => $service->id]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/events/{$event->id}"), eventPayload($service, [
            'capacity' => 8,
            'scope' => 'this',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($event->fresh()->capacity)->toBe(8);
});

it('edits only this occurrence with scope=this', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $series = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $first = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id, 'series_id' => $series,
        'starts_at' => '2026-09-01 08:00:00', 'ends_at' => '2026-09-01 09:00:00', 'capacity' => 10,
    ]);
    $second = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id, 'series_id' => $series,
        'starts_at' => '2026-09-08 08:00:00', 'ends_at' => '2026-09-08 09:00:00', 'capacity' => 10,
    ]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/events/{$first->id}"), eventPayload($service, [
            'capacity' => 25,
            'scope' => 'this',
        ]))
        ->assertRedirect();

    expect($first->fresh()->capacity)->toBe(25)
        ->and($second->fresh()->capacity)->toBe(10); // untouched
});

it('edits this and following occurrences with scope=following', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $series = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $first = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id, 'series_id' => $series,
        'starts_at' => '2026-09-01 08:00:00', 'ends_at' => '2026-09-01 09:00:00', 'capacity' => 10,
    ]);
    $second = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id, 'series_id' => $series,
        'starts_at' => '2026-09-08 08:00:00', 'ends_at' => '2026-09-08 09:00:00', 'capacity' => 10,
    ]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/events/{$first->id}"), eventPayload($service, [
            'starts_at' => '2026-09-01T10:00',
            'ends_at' => '2026-09-01T11:00',
            'capacity' => 30,
            'scope' => 'following',
        ]))
        ->assertRedirect();

    expect($first->fresh()->capacity)->toBe(30)
        ->and($second->fresh()->capacity)->toBe(30); // propagated
});

it('cancels an event', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $event = Event::factory()->forTenant($tenant)->withBookings(3)
        ->create(['service_id' => $service->id]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/events/{$event->id}/cancel"), ['scope' => 'this'])
        ->assertRedirect();

    expect($event->fresh()->status)->toBe(EventStatus::Canceled);
});

it('deletes an event with no bookings', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id]);

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/events/{$event->id}"))
        ->assertRedirect();

    $this->assertDatabaseMissing('events', ['id' => $event->id]);
});

it('blocks deleting an event that has bookings (must cancel)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $event = Event::factory()->forTenant($tenant)->withBookings(2)
        ->create(['service_id' => $service->id]);

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/events/{$event->id}"))
        ->assertSessionHasErrors('delete');

    $this->assertDatabaseHas('events', ['id' => $event->id]);
});

it('rejects waitlist when the feature is disabled', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::Waitlist, 'enabled' => false]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, ['waitlist_enabled' => true]))
        ->assertSessionHasErrors('waitlist_enabled');
});

it('rejects a service from another tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignService = eventService($other);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($foreignService))
        ->assertSessionHasErrors('service_id');
});

it('404s when updating another tenant\'s event (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignService = eventService($other);
    $foreign = Event::factory()->forTenant($other)->create(['service_id' => $foreignService->id]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/events/{$foreign->id}"), eventPayload($foreignService))
        ->assertNotFound();
});

it('forbids a customer without schedule.manage (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = eventUser($tenant, Role::Customer);

    $this->actingAs($customer)
        ->get(tenantHost('acme', '/events'))
        ->assertForbidden();
});

it('grants a manager access to events', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $manager = eventUser($tenant, Role::Manager);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/events'))
        ->assertOk();
});

it('redirects a guest to login', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/events'))->assertRedirectContains('/login');
});

it('keeps the local wall-clock time across a DST changeover in a weekly series', function () {
    // EU DST ends 2026-10-25. "Saturday 10:00 local" must stay 10:00 local on both
    // sides: Oct 24 (CEST +2 → 08:00 UTC) and Oct 31 (CET +1 → 09:00 UTC).
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'starts_at' => '2026-10-24T10:00',
            'ends_at' => '2026-10-24T11:00',
            'repeat_weekly' => true,
            'repeat_until' => '2026-10-31',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $events = Event::orderBy('starts_at')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->starts_at->toDateTimeString())->toBe('2026-10-24 08:00:00')
        ->and($events[1]->starts_at->toDateTimeString())->toBe('2026-10-31 09:00:00');
});

it('does not raise a false clash against another tenant\'s event at the same time', function () {
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $otherService = eventService($other);
    $otherStaff = Staff::factory()->forTenant($other)->create();
    Event::factory()->forTenant($other)->create([
        'service_id' => $otherService->id,
        'staff_id' => $otherStaff->id,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);

    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    // Same wall-clock time, acme's own staff — the other tenant's event is invisible.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'starts_at' => '2026-09-01T10:00',
            'ends_at' => '2026-09-01T11:00',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('rejects a staff member from another tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignStaff = Staff::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, ['staff_id' => $foreignStaff->id]))
        ->assertSessionHasErrors('staff_id');
});

it('rejects an unbounded recurrence beyond the occurrence cap', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'repeat_weekly' => true,
            'repeat_until' => '2036-09-01', // ~10 years > 260 weeks
        ]))
        ->assertSessionHasErrors('repeat_until');
});

it('rejects an invalid edit scope', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/events/{$event->id}"), eventPayload($service, ['scope' => 'bogus']))
        ->assertSessionHasErrors('scope');
});

/*
|--------------------------------------------------------------------------
| Per-occurrence clash checking in a weekly series (SLO-82)
|--------------------------------------------------------------------------
|
| The base check only ever looked at the occurrence that was submitted. Every
| later week of the series went in unchecked — so a staff member or a room could
| be booked onto two overlapping events on any week but the first, and nothing
| said so. The failure is invisible at announce time and only shows up when
| somebody reads the calendar, which is why it needs tests rather than care.
|
*/

it('rejects a series whose later occurrence clashes with an existing event', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    // The clash is on the THIRD week — the submitted occurrence (Sep 1) is clear,
    // which is exactly the case the old check waved through.
    Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-15 09:00:00',
        'status' => EventStatus::Scheduled,
    ]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'repeat_weekly' => true,
            'repeat_until' => '2026-09-29',
        ]))
        ->assertSessionHasErrors('repeat_until');

    // Nothing partially written: the whole series is refused, not trimmed.
    expect(Event::where('series_id', '!=', null)->count())->toBe(0);
});

it('names the clashing occurrences so the admin can see which weeks are taken', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-15 08:00:00', // 10:00 Europe/Budapest
        'ends_at' => '2026-09-15 09:00:00',
        'status' => EventStatus::Scheduled,
    ]);

    $response = $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'repeat_weekly' => true,
            'repeat_until' => '2026-09-29',
        ]));

    // Tenant-local wall clock, not UTC: the admin picked 10:00 and has to read
    // 10:00 back, or the message points at an hour they never chose.
    $message = session('errors')->first('repeat_until');
    expect($message)->toContain('2026. 09. 15. 10:00');
});

it('lets a series through when the clashing event is on a different resource', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $other = Staff::factory()->forTenant($tenant)->create();

    Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $other->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-15 09:00:00',
        'status' => EventStatus::Scheduled,
    ]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'repeat_weekly' => true,
            'repeat_until' => '2026-09-29',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Event::where('staff_id', $staff->id)->count())->toBe(5);
});

it('ignores a cancelled event when checking the later occurrences', function () {
    // A cancelled event does not hold its resource — the base check already says
    // so, and the series check must not be stricter than the rule it extends.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-15 09:00:00',
        'status' => EventStatus::Canceled,
    ]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'repeat_weekly' => true,
            'repeat_until' => '2026-09-29',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('does not let another tenant\'s event block a series', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $stranger = Tenant::factory()->active()->create(['slug' => 'other']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    // Same staff id space, different tenant: the query must not see it at all.
    $strangerService = eventService($stranger);
    Event::factory()->forTenant($stranger)->create([
        'service_id' => $strangerService->id,
        'staff_id' => Staff::factory()->forTenant($stranger)->create()->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-15 09:00:00',
        'status' => EventStatus::Scheduled,
    ]);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'repeat_weekly' => true,
            'repeat_until' => '2026-09-29',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('rejects a weekly series whose occurrence is a week or longer', function () {
    // Week 2 would start before week 1 finished — the series collides with
    // itself, on its own staff and room, and no existing event is involved.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'ends_at' => '2026-09-09T10:00', // 8 days long
            'repeat_weekly' => true,
            'repeat_until' => '2026-10-01',
        ]))
        ->assertSessionHasErrors('ends_at');

    expect(Event::count())->toBe(0);
});

it('allows a multi-day event that still fits inside the week', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'ends_at' => '2026-09-03T18:00', // a 2.5-day workshop
            'repeat_weekly' => true,
            'repeat_until' => '2026-09-22',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Event::count())->toBe(4);
});

it('runs one query for the whole series, not one per occurrence', function () {
    // A 260-occurrence series behind a form submit is exactly what the N+1 guard
    // exists to stop; the candidate events load once for the whole span.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = eventUser($tenant, Role::TenantAdmin);
    $service = eventService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'from `events`') && str_contains($query->sql, 'scheduled')) {
            $queries++;
        }
    });

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/events'), eventPayload($service, [
            'staff_id' => $staff->id,
            'repeat_weekly' => true,
            'repeat_until' => '2027-08-25', // ~52 occurrences
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // One for the base occurrence (validateNoResourceClash), one for the rest.
    expect($queries)->toBeLessThanOrEqual(2);
});
