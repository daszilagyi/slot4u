<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
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
function bkTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function bkUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

/**
 * A confirmed duration booking for the tenant with a fresh same-tenant service and
 * (optionally) a staff record.
 */
function bkBooking(Tenant $tenant, array $overrides = [], ?Staff $staff = null): Booking
{
    $service = Service::factory()->forTenant($tenant)->create();

    return Booking::factory()->forTenant($tenant)->create(array_merge([
        'service_id' => $service->id,
        'staff_id' => $staff?->id,
    ], $overrides));
}

// --- List + filters ---

it('lists bookings for a tenant admin', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    bkBooking($tenant);
    bkBooking($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Bookings/Index')
            ->has('bookings.data', 2)
            ->has('options.statuses')
            ->has('options.services'));
});

it('reports the approve ability the quick-action menu needs', function () {
    // The approve action shares the list's quick-action code path (SLO-136), so the
    // list has to say whether the actor may use it — permission AND feature (docs/03).
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $employee = bkUser($tenant, Role::Employee);
    Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.approve', true));

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(TenantManager::class)->forget();

    // An employee holds no booking.approve (docs/03 matrix).
    $this->actingAs($employee)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.approve', false));
});

it('ships the rooms the "offer another time" form needs', function () {
    // Not a list filter: the proposal (SLO-144) may move the booking to another room,
    // so the picker has to have them.
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    Room::factory()->forTenant($tenant)->create(['name' => 'Nagyterem']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('options.rooms', 1)
            ->where('options.rooms.0.name', 'Nagyterem'));
});

it('withholds the approve ability when feature_approval_flow is off', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    TenantFeature::factory()->create(['feature_code' => Feature::ApprovalFlow, 'enabled' => false]);
    $admin = bkUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.approve', false));
});

it('filters bookings by status', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    bkBooking($tenant, ['status' => BookingStatus::Confirmed]);
    bkBooking($tenant, ['status' => BookingStatus::Canceled]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/bookings?status=canceled'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.data.0.status', 'canceled'));
});

it('filters bookings by staff', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $other = Staff::factory()->forTenant($tenant)->create();
    bkBooking($tenant, [], $staff);
    bkBooking($tenant, [], $other);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings?staff_id={$staff->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.data.0.staff', $staff->name));
});

it('combines service and date-range filters', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $service = Service::factory()->forTenant($tenant)->create();

    // In range + right service.
    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);
    // Right service, out of range.
    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'starts_at' => '2026-09-10 08:00:00',
        'ends_at' => '2026-09-10 09:00:00',
    ]);
    // In range, different service.
    bkBooking($tenant, ['starts_at' => '2026-09-01 08:00:00', 'ends_at' => '2026-09-01 09:00:00']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings?service_id={$service->id}&from=2026-09-01&to=2026-09-02"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('bookings.data', 1));
});

it('keeps the booking list query N+1-free as rows grow', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);

    // Bookings with all three eager-loaded relations set (customer/service/staff),
    // so an unbatched load would show up as extra per-row queries.
    $addBooking = function () use ($tenant) {
        app(TenantManager::class)->set($tenant); // ambient tenant for creation; does NOT touch the spatie cache
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff = Staff::factory()->forTenant($tenant)->create();
        $service = Service::factory()->forTenant($tenant)->create();
        Booking::factory()->forTenant($tenant)->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_id' => $staff->id,
        ]);
        app(TenantManager::class)->forget();
    };

    // Each measurement re-warms the permission cache first, so the counts are
    // comparable (no cross-request cache-state artifact).
    $measure = function () use ($admin) {
        $this->actingAs($admin)->get(tenantHost('acme', '/bookings'))->assertOk();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(tenantHost('acme', '/bookings'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    };

    $addBooking();
    $few = $measure();

    $addBooking();
    $addBooking();
    $addBooking();
    $many = $measure();

    // Eager loading → the query count is constant regardless of row count.
    expect($many)->toBe($few);
});

// --- Employee ownership scope ---

it('shows an employee only their own bookings', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $employee = bkUser($tenant, Role::Employee);
    $staff = Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $otherStaff = Staff::factory()->forTenant($tenant)->create();

    $mine = bkBooking($tenant, [], $staff);
    bkBooking($tenant, [], $otherStaff);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.data.0.id', $mine->id));
});

it('404s when an employee opens a booking outside their scope', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $employee = bkUser($tenant, Role::Employee);
    Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $otherStaff = Staff::factory()->forTenant($tenant)->create();
    $theirs = bkBooking($tenant, [], $otherStaff);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', "/bookings/{$theirs->id}"))
        ->assertNotFound();
});

it('shows a manager every booking', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $manager = bkUser($tenant, Role::Manager);
    bkBooking($tenant);
    bkBooking($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('bookings.data', 2));
});

// --- Show + history ---

it('shows a booking card with status history', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    // The model's created hook records the initial status (from null) as the first
    // history entry; a cancel adds a second (confirmed → canceled).
    $booking = bkBooking($tenant, ['status' => BookingStatus::Confirmed]);
    $booking->statusHistory()->create(['from_status' => 'confirmed', 'to_status' => 'canceled', 'actor_id' => $admin->id]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings/{$booking->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Bookings/Show')
            ->where('booking.code', $booking->code)
            ->has('booking.history', 2)
            ->where('booking.history.0.from_status', null));
});

it('shows a guest booking with the contact from the row, flagged as a guest', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    // A guest booking (SLO-128): no account, the contact lives on the booking.
    $booking = bkBooking($tenant, [
        'customer_id' => null,
        'guest_name' => 'Teszt Vendég',
        'guest_email' => 'guest@example.test',
        'guest_phone' => '+3611234567',
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/bookings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('bookings.data.0.customer', 'Teszt Vendég')
            ->where('bookings.data.0.is_guest', true));

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings/{$booking->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('booking.customer', 'Teszt Vendég')
            ->where('booking.customer_email', 'guest@example.test')
            ->where('booking.customer_phone', '+3611234567')
            ->where('booking.is_guest', true));
});

it('does not flag a customer-backed booking as a guest', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Régi Ügyfél']);
    $booking = bkBooking($tenant, ['customer_id' => $customer->id]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings/{$booking->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('booking.customer', 'Régi Ügyfél')
            ->where('booking.is_guest', false));
});

// --- Quick actions ---

it('lets an admin cancel a booking', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $booking = bkBooking($tenant, ['status' => BookingStatus::Confirmed]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/cancel"), ['reason' => 'Ügyfél kérése'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($booking->fresh()->cancel_reason)->toBe('Ügyfél kérése');
});

it('lets an admin mark a booking as no-show', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $booking = bkBooking($tenant, ['status' => BookingStatus::Confirmed]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/no-show"))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('lets an admin complete a no_time_slot booking', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::NoTimeSlot)->create();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'booking_mode' => BookingMode::NoTimeSlot,
        'status' => BookingStatus::Confirmed,
        'starts_at' => null,
        'ends_at' => null,
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/complete"))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

it('reschedules a booking: cancels the original and creates a new confirmed one', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = bkBooking($tenant, [
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ], $staff);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-02T10:00',
            'ends_at' => '2026-09-02T11:00',
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(TenantManager::class)->set($tenant);

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and(Booking::where('status', BookingStatus::Confirmed)->count())->toBe(1);
});

it('refuses to reschedule a no_time_slot booking (guarded server-side)', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::NoTimeSlot)->create();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'booking_mode' => BookingMode::NoTimeSlot,
        'status' => BookingStatus::Confirmed,
        'starts_at' => null,
        'ends_at' => null,
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-02T10:00',
            'ends_at' => '2026-09-02T11:00',
        ])
        ->assertSessionHasErrors('starts_at');

    // The original is untouched — no slot was assigned, nothing was canceled.
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->fresh()->starts_at)->toBeNull();
});

it('refuses to reschedule a quote_request booking without a 500', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::QuoteRequest)->create();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'booking_mode' => BookingMode::QuoteRequest,
        'status' => BookingStatus::Confirmed,
        'starts_at' => null,
        'ends_at' => null,
    ]);
    app(TenantManager::class)->forget();

    // The mode guard fires before CreateBooking's quote RuntimeException — a clean
    // 422 (session error), not an unhandled 500.
    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-02T10:00',
            'ends_at' => '2026-09-02T11:00',
        ])
        ->assertSessionHasErrors('starts_at');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('applies tenant-local day boundaries to the date filter across DST', function () {
    // Europe/Budapest springs forward 2026-03-29 02:00 → 03:00. The from-day starts
    // in CET (+1): 2026-03-29 00:00 local = 2026-03-28 23:00 UTC.
    $tenant = bkTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = bkUser($tenant, Role::TenantAdmin);

    $inside = Booking::factory()->forTenant($tenant)->create([
        'starts_at' => '2026-03-29 08:00:00', // 09:00/10:00 local — inside the day
        'ends_at' => '2026-03-29 09:00:00',
    ]);
    Booking::factory()->forTenant($tenant)->create([
        'starts_at' => '2026-03-28 22:00:00', // 23:00 local on the 28th — before the day
        'ends_at' => '2026-03-28 23:00:00',
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/bookings?from=2026-03-29&to=2026-03-29'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.data.0.id', $inside->id));
});

it('404s when an employee cancels a booking outside their scope', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $employee = bkUser($tenant, Role::Employee);
    Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $otherStaff = Staff::factory()->forTenant($tenant)->create();
    $theirs = bkBooking($tenant, ['status' => BookingStatus::Confirmed], $otherStaff);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', "/bookings/{$theirs->id}/cancel"))
        ->assertNotFound();

    expect($theirs->fresh()->status)->toBe(BookingStatus::Confirmed);
});

// --- Authorization + isolation ---

it('forbids the booking list without booking.view (403)', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]); // no roles
    app(TenantManager::class)->forget();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/bookings'))
        ->assertForbidden();
});

it('404s when opening another tenant\'s booking (cross-tenant)', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);

    $other = bkTenant(['slug' => 'other']);
    $foreign = bkBooking($other);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings/{$foreign->id}"))
        ->assertNotFound();
});

// --- Reschedule from the calendar's drag-and-drop (SLO-44) ---

it('keeps the booking length when the reschedule only submits a new start', function () {
    $tenant = bkTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = bkBooking($tenant, [
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-01 06:00:00', // 08:00–09:30 local, 90 minutes
        'ends_at' => '2026-09-01 07:30:00',
    ], $staff);
    app(TenantManager::class)->forget();

    // A drag picks a start, never an end.
    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-02T10:00',
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(TenantManager::class)->set($tenant);

    $moved = Booking::where('status', BookingStatus::Confirmed)->sole();

    // 10:00 local on 2026-09-02 is 08:00 UTC (CEST), and the 90 minutes survive.
    expect($moved->starts_at->toDateTimeString())->toBe('2026-09-02 08:00:00')
        ->and($moved->ends_at->toDateTimeString())->toBe('2026-09-02 09:30:00')
        ->and($moved->rescheduled_from_id)->toBe($booking->id);
});

it('preserves the real duration when a move crosses the spring-forward hour', function () {
    // Europe/Budapest springs forward 2026-03-29 02:00 → 03:00. A 60-minute booking
    // dragged onto 02:30 local must stay 60 real minutes: adding wall-clock minutes
    // would have produced an end that never existed on the clock.
    $tenant = bkTenant(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = bkBooking($tenant, [
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-03-20 09:00:00',
        'ends_at' => '2026-03-20 10:00:00',
    ], $staff);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-03-29T01:30',
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(TenantManager::class)->set($tenant);

    $moved = Booking::where('status', BookingStatus::Confirmed)->sole();

    // 01:30 CET = 00:30 UTC; one real hour later is 01:30 UTC (= 03:30 CEST local).
    expect($moved->starts_at->toDateTimeString())->toBe('2026-03-29 00:30:00')
        ->and($moved->ends_at->toDateTimeString())->toBe('2026-03-29 01:30:00')
        ->and($moved->starts_at->diffInMinutes($moved->ends_at))->toBe(60.0);
});

it('carries a guest booking contact over to the replacement', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    // A guest booking (SLO-128) has no account holding the contact details.
    $booking = bkBooking($tenant, [
        'status' => BookingStatus::Confirmed,
        'customer_id' => null,
        'guest_name' => 'Kovács Béla',
        'guest_email' => 'bela@example.test',
        'guest_phone' => '+36301234567',
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ], $staff);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-02T10:00',
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(TenantManager::class)->set($tenant);

    $moved = Booking::where('status', BookingStatus::Confirmed)->sole();

    expect($moved->guest_email)->toBe('bela@example.test')
        ->and($moved->guest_name)->toBe('Kovács Béla')
        ->and($moved->guest_phone)->toBe('+36301234567')
        ->and($moved->customer_id)->toBeNull();
});

it('reassigns the staff when the card is dropped on another resource column', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $from = Staff::factory()->forTenant($tenant)->create();
    $to = Staff::factory()->forTenant($tenant)->create();
    $booking = bkBooking($tenant, [
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ], $from);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-01T10:00',
            'staff_id' => $to->id,
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(TenantManager::class)->set($tenant);

    expect(Booking::where('status', BookingStatus::Confirmed)->sole()->staff_id)->toBe($to->id);
});

it('leaves the booking where it was when the target slot is taken', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service = Service::factory()->forTenant($tenant)->create();

    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);
    // Someone else already holds the slot the card was dropped on.
    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-01 12:00:00',
        'ends_at' => '2026-09-01 13:00:00',
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-01T14:00', // 12:00 UTC — straight onto the rival
            'staff_id' => $staff->id,
        ])
        ->assertSessionHasErrors('booking');

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(TenantManager::class)->set($tenant);

    // The whole transaction rolled back, so the card snaps back to a booking that
    // never moved — there is nothing for the UI to undo.
    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Confirmed)
        ->and($fresh->starts_at->toDateTimeString())->toBe('2026-09-01 08:00:00');
});

it('rejects an end that is not after the start', function () {
    $tenant = bkTenant(['slug' => 'acme']);
    $admin = bkUser($tenant, Role::TenantAdmin);
    $booking = bkBooking($tenant, [
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);
    app(TenantManager::class)->forget();

    // ends_at became optional for the drag, but a submitted one is still validated.
    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reschedule"), [
            'starts_at' => '2026-09-02T10:00',
            'ends_at' => '2026-09-02T09:00',
        ])
        ->assertSessionHasErrors('ends_at');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});
