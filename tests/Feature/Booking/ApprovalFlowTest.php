<?php

use App\Actions\Booking\ApproveBooking;
use App\Actions\Booking\CreateBooking;
use App\Actions\Booking\ProposeAlternativeTime;
use App\Actions\Booking\RejectBooking;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\InvalidBookingTransitionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function apTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);

    return $tenant;
}

function apService(Tenant $tenant, array $overrides = []): Service
{
    return Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_staff' => true, 'requires_approval' => true, 'online_payment_required' => false,
        'price_minor' => 500000, 'currency' => 'HUF',
    ], $overrides));
}

function apSlot(Staff $staff, array $overrides = []): array
{
    return array_merge([
        'staff_id' => $staff->id, 'starts_at' => '2026-09-07 10:00:00', 'ends_at' => '2026-09-07 11:00:00',
        'party_size' => 1, 'source' => 'online',
    ], $overrides);
}

function apAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

// --- Soft hold ---

it('starts an approval-required online booking as requested with a soft hold', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $booking = app(CreateBooking::class)($service, apSlot($staff));

    expect($booking->status)->toBe(BookingStatus::Requested)
        ->and($booking->hold_expires_at)->not->toBeNull()
        ->and($booking->hold_expires_at->isFuture())->toBeTrue();
});

it('gives an admin booking no soft hold', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();

    $booking = app(CreateBooking::class)($service, apSlot($staff, ['source' => 'admin']));

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->hold_expires_at)->toBeNull();
});

it('soft-holds the slot so another booking cannot take it', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    app(CreateBooking::class)($service, apSlot($staff)); // requested, holds the slot

    expect(fn () => app(CreateBooking::class)($service, apSlot($staff, ['starts_at' => '2026-09-07 10:30:00', 'ends_at' => '2026-09-07 11:30:00'])))
        ->toThrow(SlotUnavailableException::class);
});

it('releases an expired soft hold and frees the slot', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $stale = Booking::factory()->forTenant($tenant)->requested('2020-01-01 00:00:00')->create([
        'service_id' => $service->id, 'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 10:00:00', 'ends_at' => '2026-09-07 11:00:00',
    ]);

    $this->artisan('bookings:expire-soft-holds')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($stale->fresh()->hold_expires_at)->toBeNull();

    // Slot is free again → a new booking succeeds.
    expect(app(CreateBooking::class)($service, apSlot($staff))->status)->toBe(BookingStatus::Requested);
});

it('does not release a soft hold that is still within its window', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = Booking::factory()->forTenant($tenant)->requested(now()->addHours(10)->toDateTimeString())->create([
        'service_id' => $service->id, 'staff_id' => $staff->id,
    ]);

    $this->artisan('bookings:expire-soft-holds')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Requested);
});

it('expires holds per tenant without touching another tenant', function () {
    // Tenant A: a lapsed hold that should be released.
    $tenantA = apTenant(['slug' => 'acme']);
    $serviceA = apService($tenantA);
    $staffA = Staff::factory()->forTenant($tenantA)->create();
    $stale = Booking::factory()->forTenant($tenantA)->requested('2020-01-01 00:00:00')->create([
        'service_id' => $serviceA->id, 'staff_id' => $staffA->id,
    ]);

    // Tenant B: a fresh hold that must be left alone.
    $tenantB = apTenant(['slug' => 'other']);
    $serviceB = apService($tenantB);
    $staffB = Staff::factory()->forTenant($tenantB)->create();
    $fresh = Booking::factory()->forTenant($tenantB)->requested(now()->addHours(10)->toDateTimeString())->create([
        'service_id' => $serviceB->id, 'staff_id' => $staffB->id,
    ]);
    app(TenantManager::class)->forget();

    $this->artisan('bookings:expire-soft-holds')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($fresh->fresh()->status)->toBe(BookingStatus::Requested);
});

// --- Approve / reject ---

it('approves a requested booking to confirmed and clears the hold', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $actor = apAdmin($tenant);
    $booking = app(CreateBooking::class)($service, apSlot($staff));

    app(ApproveBooking::class)($booking, $actor);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->fresh()->hold_expires_at)->toBeNull()
        ->and($booking->fresh()->approved_by)->toBe($actor->id)
        ->and($booking->fresh()->approved_at)->not->toBeNull();

    // History records requested → approved → confirmed.
    $trail = $booking->statusHistory()->orderBy('id')->pluck('to_status')
        ->map(fn ($s) => $s instanceof BookingStatus ? $s->value : $s)->all();
    expect($trail)->toBe(['requested', 'approved', 'confirmed']);
});

it('approves a payment-required booking to pending_payment', function () {
    $tenant = apTenant();
    $service = apService($tenant, ['online_payment_required' => true]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff));

    app(ApproveBooking::class)($booking, apAdmin($tenant));

    expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
});

it('rejects a requested booking with a reason and frees the slot', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff));

    app(RejectBooking::class)($booking, apAdmin($tenant), 'Nincs szabad kapacitás.');

    expect($booking->fresh()->status)->toBe(BookingStatus::Rejected)
        ->and($booking->fresh()->reject_reason)->toBe('Nincs szabad kapacitás.')
        ->and($booking->fresh()->hold_expires_at)->toBeNull();

    // Slot freed.
    expect(app(CreateBooking::class)($service, apSlot($staff))->status)->toBe(BookingStatus::Requested);
});

// --- Propose alternative time ---

it('proposes an alternative: rejects the original and requests the new slot', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $original = app(CreateBooking::class)($service, apSlot($staff));

    $new = app(ProposeAlternativeTime::class)($original, [
        'staff_id' => $staff->id, 'starts_at' => '2026-09-07 14:00:00', 'ends_at' => '2026-09-07 15:00:00',
    ], apAdmin($tenant));

    expect($original->fresh()->status)->toBe(BookingStatus::Rejected)
        ->and($new->status)->toBe(BookingStatus::Requested)
        ->and($new->hold_expires_at)->not->toBeNull()
        ->and($new->starts_at->toDateTimeString())->toBe('2026-09-07 14:00:00')
        ->and($new->id)->not->toBe($original->id);
});

it('refuses to propose an alternative for an event_based booking', function () {
    $tenant = apTenant();
    $service = apService($tenant, ['booking_mode' => BookingMode::EventBased, 'requires_staff' => false]);
    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id, 'capacity' => 5, 'booked_count' => 0]);
    $booking = app(CreateBooking::class)($service, ['event_id' => $event->id, 'party_size' => 1, 'source' => 'online']);

    expect(fn () => app(ProposeAlternativeTime::class)($booking, [
        'starts_at' => '2026-09-07 14:00:00', 'ends_at' => '2026-09-07 15:00:00',
    ], apAdmin($tenant)))->toThrow(ValidationException::class);

    // The original request is untouched (no silent corruption).
    expect($booking->fresh()->status)->toBe(BookingStatus::Requested)
        ->and($booking->fresh()->starts_at->toDateTimeString())->toBe($event->starts_at->toDateTimeString());
});

it('refuses to propose an alternative for a no_time_slot booking', function () {
    $tenant = apTenant();
    $service = apService($tenant, ['booking_mode' => BookingMode::NoTimeSlot, 'requires_staff' => false]);
    $booking = app(CreateBooking::class)($service, ['party_size' => 1, 'source' => 'online']);

    expect(fn () => app(ProposeAlternativeTime::class)($booking, [
        'starts_at' => '2026-09-07 14:00:00', 'ends_at' => '2026-09-07 15:00:00',
    ], apAdmin($tenant)))->toThrow(ValidationException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Requested)
        ->and($booking->fresh()->starts_at)->toBeNull();
});

it('rolls back a proposal when the new slot is unavailable (original survives)', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $original = app(CreateBooking::class)($service, apSlot($staff));
    // Block the proposed slot.
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'staff_id' => $staff->id, 'starts_at' => '2026-09-07 14:00:00', 'ends_at' => '2026-09-07 15:00:00',
    ]);

    expect(fn () => app(ProposeAlternativeTime::class)($original, [
        'staff_id' => $staff->id, 'starts_at' => '2026-09-07 14:00:00', 'ends_at' => '2026-09-07 15:00:00',
    ], apAdmin($tenant)))->toThrow(SlotUnavailableException::class);

    expect($original->fresh()->status)->toBe(BookingStatus::Requested);
});

// --- Concurrency guard (SLO-26: background job vs admin) ---

it('refuses a status change when the row already moved underneath it', function () {
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff)); // requested, in-memory

    // Simulate a concurrent writer (e.g. the expiry job) moving the row first.
    Booking::withoutGlobalScopes()->whereKey($booking->id)->update(['status' => BookingStatus::Canceled->value]);

    // The stale in-memory booking still thinks it is requested; the lock re-check
    // catches the real state and refuses to clobber it.
    expect(fn () => app(ApproveBooking::class)($booking, apAdmin($tenant)))
        ->toThrow(InvalidBookingTransitionException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled);
});

// --- Event capacity release on expiry (SLO-25 integration) ---

it('returns event capacity when a requested event booking soft-hold expires', function () {
    $tenant = apTenant();
    $service = apService($tenant, ['booking_mode' => BookingMode::EventBased, 'requires_staff' => false]);
    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id, 'capacity' => 5, 'booked_count' => 0]);
    $booking = app(CreateBooking::class)($service, ['event_id' => $event->id, 'party_size' => 2, 'source' => 'online']);
    expect($booking->status)->toBe(BookingStatus::Requested)
        ->and($event->fresh()->booked_count)->toBe(2);

    // Force the hold into the past, then expire.
    $booking->hold_expires_at = '2020-01-01 00:00:00';
    $booking->save();
    $this->artisan('bookings:expire-soft-holds')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($event->fresh()->booked_count)->toBe(0);
});

// --- HTTP ---

it('lets an admin approve via the endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff));
    $admin = apAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/approve"))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('lets an admin reject via the endpoint with a reason', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff));
    $admin = apAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reject"), ['reason' => 'Zárva aznap.'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Rejected)
        ->and($booking->fresh()->reject_reason)->toBe('Zárva aznap.');
});

it('requires a reason to reject via the endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff));
    $admin = apAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reject"), [])
        ->assertSessionHasErrors('reason');
});

it('returns 422 when approving a booking that is not requested', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff, ['source' => 'admin'])); // confirmed
    $admin = apAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/approve"))
        ->assertSessionHasErrors('booking');
});

it('blocks approval when feature_approval_flow is disabled (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::ApprovalFlow, 'enabled' => false]);
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff));
    $admin = apAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/approve"))
        ->assertForbidden();
});

it('forbids approval without booking.approve (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff));
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $this->actingAs($user)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/approve"))
        ->assertForbidden();
});

// --- Proposal keeps what the original knew (SLO-144) ---

it('keeps the original resource when the proposal does not name one', function () {
    // The form only has to name a staff/room when the offer actually moves it; a
    // missing field must not strip the booking's resource off the proposal.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff));
    $admin = apAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/propose"), [
            'starts_at' => '2026-09-07T14:00',
            'ends_at' => '2026-09-07T15:00',
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    $proposed = Booking::query()->where('tenant_id', $tenant->id)
        ->where('status', BookingStatus::Requested->value)->latest('id')->firstOrFail();

    expect($proposed->staff_id)->toBe($staff->id)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Rejected);
});

it('carries a guest booking contact over to the proposed slot', function () {
    // A guest booking (SLO-128) has no account holding the contact details, so
    // without this the offer would land on a booking nobody can be notified about.
    $tenant = apTenant();
    $service = apService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($staff, [
        'guest_name' => 'Vendég Viktor',
        'guest_email' => 'vendeg@example.test',
        'guest_phone' => '+36301234567',
    ]));

    $proposed = app(ProposeAlternativeTime::class)($booking, [
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 14:00:00',
        'ends_at' => '2026-09-07 15:00:00',
    ], apAdmin($tenant));

    expect($proposed->guest_name)->toBe('Vendég Viktor')
        ->and($proposed->guest_email)->toBe('vendeg@example.test')
        ->and($proposed->guest_phone)->toBe('+36301234567')
        ->and($proposed->isGuest())->toBeTrue();
});

it('404s when an employee decides on a booking outside their scope', function () {
    // Hidden existence, like a cross-tenant id: a 403 here would confirm that a
    // colleague's booking exists. The policy would have refused it anyway.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = apService($tenant);
    $colleague = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, apSlot($colleague));

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee = User::factory()->create(['tenant_id' => $tenant->id]);
    $employee->assignRole(Role::Employee->value);
    // Employees hold no booking.approve by default (docs/03), so grant it directly:
    // the point of this test is the ownership 404, not the permission.
    $employee->givePermissionTo(Permission::BookingApprove->value);
    Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $actor = User::query()->findOrFail($employee->getKey());

    $this->actingAs($actor)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/approve"))
        ->assertNotFound();

    $this->actingAs($actor)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/reject"), ['reason' => 'Nem.'])
        ->assertNotFound();

    expect($booking->fresh()->status)->toBe(BookingStatus::Requested);
});

it('404s when approving another tenant\'s booking (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $admin = apAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    $service = apService($other);
    $staff = Staff::factory()->forTenant($other)->create();
    $foreign = app(CreateBooking::class)($service, apSlot($staff));
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$foreign->id}/approve"))
        ->assertNotFound();
});
