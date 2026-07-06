<?php

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CreateBooking;
use App\Actions\Event\CancelEvent;
use App\Actions\Waitlist\JoinWaitlist;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\Feature;
use App\Enums\Role;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Booking\WaitlistService;
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

/** Bind the current tenant so BelongsToTenant stamps waitlist rows. */
function wlTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);

    return $tenant;
}

function wlService(Tenant $tenant): Service
{
    return Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::EventBased,
        'price_minor' => 100000,
        'requires_staff' => false,
    ]);
}

/** An event filled to capacity, waitlist on by default. */
function wlEvent(Tenant $tenant, Service $service, int $capacity = 1, ?int $bookedCount = null, bool $waitlist = true): Event
{
    return Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'capacity' => $capacity,
        'booked_count' => $bookedCount ?? $capacity,
        'waitlist_enabled' => $waitlist,
    ]);
}

function wlCustomer(Tenant $tenant): User
{
    return User::factory()->create(['tenant_id' => $tenant->id]);
}

/** A user with booking.create in the tenant. */
function wlAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

// --- Capacity release (the SLO-25 decrement gap) ---

it('returns event capacity when an event booking is canceled', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 5, bookedCount: 0, waitlist: false);
    $customer = wlCustomer($tenant);

    $booking = app(CreateBooking::class)($service, [
        'event_id' => $event->id, 'customer_id' => $customer->id, 'party_size' => 3, 'source' => 'admin',
    ]);
    expect($event->fresh()->booked_count)->toBe(3);

    app(CancelBooking::class)($booking, reason: 'test');

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($event->fresh()->booked_count)->toBe(0);
});

it('does not go negative if capacity was already zero', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 5, bookedCount: 0, waitlist: false);
    // A stray canceled event booking whose seats were never counted.
    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'booking_mode' => BookingMode::EventBased, 'service_id' => $service->id,
        'event_id' => $event->id, 'party_size' => 2,
    ]);

    app(CancelBooking::class)($booking, reason: 'test');

    expect($event->fresh()->booked_count)->toBe(0);
});

it('does not touch capacity for a time-slot booking', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 2, bookedCount: 2, waitlist: false);
    // A duration booking that happens to reference nothing about the event.
    $slotService = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'requires_staff' => false,
    ]);
    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'booking_mode' => BookingMode::DurationBased, 'service_id' => $slotService->id,
    ]);

    app(CancelBooking::class)($booking, reason: 'test');

    expect($event->fresh()->booked_count)->toBe(2);
});

// --- Joining the waitlist ---

it('adds a customer to a full event at the next FIFO position', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 1);

    $first = app(JoinWaitlist::class)($event, wlCustomer($tenant)->id, 2);
    $second = app(JoinWaitlist::class)($event, wlCustomer($tenant)->id);

    expect($first->position)->toBe(1)
        ->and($first->party_size)->toBe(2)
        ->and($first->status)->toBe(WaitlistStatus::Waiting)
        ->and($second->position)->toBe(2);
});

it('refuses to waitlist an event that still has room', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 5, bookedCount: 0);

    expect(fn () => app(JoinWaitlist::class)($event, wlCustomer($tenant)->id))
        ->toThrow(ValidationException::class);
});

it('refuses to waitlist an event with waitlist disabled', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 1, waitlist: false);

    expect(fn () => app(JoinWaitlist::class)($event, wlCustomer($tenant)->id))
        ->toThrow(ValidationException::class);
});

it('refuses to list the same customer twice on one event', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 1);
    $customer = wlCustomer($tenant);

    app(JoinWaitlist::class)($event, $customer->id);

    expect(fn () => app(JoinWaitlist::class)($event, $customer->id))
        ->toThrow(ValidationException::class);
});

// --- Offering a freed seat ---

it('offers the freed seat to the first waiter when an event booking cancels', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 1, bookedCount: 0);
    $booking = app(CreateBooking::class)($service, [
        'event_id' => $event->id, 'customer_id' => wlCustomer($tenant)->id, 'party_size' => 1, 'source' => 'admin',
    ]);
    $waiter = app(JoinWaitlist::class)($event, wlCustomer($tenant)->id);

    app(CancelBooking::class)($booking, reason: 'test');

    expect($event->fresh()->booked_count)->toBe(0)
        ->and($waiter->fresh()->status)->toBe(WaitlistStatus::Offered)
        ->and($waiter->fresh()->offered_until)->not->toBeNull();
});

it('offers strictly one waiter at a time per event', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 2, bookedCount: 0);
    $b1 = app(CreateBooking::class)($service, ['event_id' => $event->id, 'customer_id' => wlCustomer($tenant)->id, 'party_size' => 1, 'source' => 'admin']);
    $b2 = app(CreateBooking::class)($service, ['event_id' => $event->id, 'customer_id' => wlCustomer($tenant)->id, 'party_size' => 1, 'source' => 'admin']);
    $w1 = app(JoinWaitlist::class)($event, wlCustomer($tenant)->id);
    $w2 = app(JoinWaitlist::class)($event, wlCustomer($tenant)->id);

    app(CancelBooking::class)($b1, reason: 'test');
    app(CancelBooking::class)($b2, reason: 'test');

    expect($w1->fresh()->status)->toBe(WaitlistStatus::Offered)
        ->and($w2->fresh()->status)->toBe(WaitlistStatus::Waiting);
});

it('converts an offered waiter who books and offers the next in line', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 2, bookedCount: 0);
    $b1 = app(CreateBooking::class)($service, ['event_id' => $event->id, 'customer_id' => wlCustomer($tenant)->id, 'party_size' => 1, 'source' => 'admin']);
    $b2 = app(CreateBooking::class)($service, ['event_id' => $event->id, 'customer_id' => wlCustomer($tenant)->id, 'party_size' => 1, 'source' => 'admin']);
    $c1 = wlCustomer($tenant);
    $c2 = wlCustomer($tenant);
    $w1 = app(JoinWaitlist::class)($event, $c1->id);
    $w2 = app(JoinWaitlist::class)($event, $c2->id);

    // Two seats free; only w1 is offered (serial FIFO).
    app(CancelBooking::class)($b1, reason: 'test');
    app(CancelBooking::class)($b2, reason: 'test');

    // The offered customer books → converted, and w2 is chained an offer.
    app(CreateBooking::class)($service, ['event_id' => $event->id, 'customer_id' => $c1->id, 'party_size' => 1, 'source' => 'admin']);

    expect($w1->fresh()->status)->toBe(WaitlistStatus::Converted)
        ->and($w2->fresh()->status)->toBe(WaitlistStatus::Offered)
        ->and($event->fresh()->booked_count)->toBe(1);
});

it('does not offer a waiter whose party is larger than the freed capacity', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    // Capacity 5, 4 seats taken → 1 free. The head waiter wants 3 → does not fit.
    $event = wlEvent($tenant, $service, capacity: 5, bookedCount: 4);
    $waiter = WaitlistEntry::factory()->forEvent($event)->position(1)->state(['party_size' => 3])->create();

    app(WaitlistService::class)->offerNext($event->id, $tenant->id);

    expect($waiter->fresh()->status)->toBe(WaitlistStatus::Waiting);

    // Once enough seats free (2 taken → 3 free), it fits. booked_count is guarded,
    // so set it directly rather than via mass assignment.
    $event->booked_count = 2;
    $event->save();
    app(WaitlistService::class)->offerNext($event->id, $tenant->id);

    expect($waiter->fresh()->status)->toBe(WaitlistStatus::Offered);
});

it('holds strict FIFO — a smaller party behind a too-big head waiter is not leapfrogged', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 5, bookedCount: 4); // 1 free
    $head = WaitlistEntry::factory()->forEvent($event)->position(1)->state(['party_size' => 3])->create();
    $behind = WaitlistEntry::factory()->forEvent($event)->position(2)->state(['party_size' => 1])->create();

    app(WaitlistService::class)->offerNext($event->id, $tenant->id);

    // Neither is offered: the head doesn't fit, and we don't leapfrog to the fitting one.
    expect($head->fresh()->status)->toBe(WaitlistStatus::Waiting)
        ->and($behind->fresh()->status)->toBe(WaitlistStatus::Waiting);
});

// --- Expiring offers ---

it('expires a lapsed offer and offers the next waiter', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 2, bookedCount: 0);
    $w1 = WaitlistEntry::factory()->forEvent($event)->position(1)->offered('2020-01-01 00:00:00')->create();
    $w2 = WaitlistEntry::factory()->forEvent($event)->position(2)->create();

    $expired = app(WaitlistService::class)->expireDueOffers();

    expect($expired)->toBe(1)
        ->and($w1->fresh()->status)->toBe(WaitlistStatus::Expired)
        ->and($w2->fresh()->status)->toBe(WaitlistStatus::Offered);
});

it('does not expire an offer still inside its window', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 2, bookedCount: 0);
    $w1 = WaitlistEntry::factory()->forEvent($event)->position(1)
        ->offered(now()->addHours(5)->toDateTimeString())->create();

    expect(app(WaitlistService::class)->expireDueOffers())->toBe(0)
        ->and($w1->fresh()->status)->toBe(WaitlistStatus::Offered);
});

it('runs the expiry command across tenants', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 2, bookedCount: 0);
    WaitlistEntry::factory()->forEvent($event)->position(1)->offered('2020-01-01 00:00:00')->create();
    app(TenantManager::class)->forget();

    $this->artisan('waitlist:expire-offers')->assertSuccessful();
});

// --- Canceled events ---

it('does not offer a seat on a canceled event', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 1, bookedCount: 0);
    $event->status = EventStatus::Canceled;
    $event->save();
    $waiter = WaitlistEntry::factory()->forEvent($event)->position(1)->create();

    expect(app(WaitlistService::class)->offerNext($event->id, $tenant->id))->toBeNull()
        ->and($waiter->fresh()->status)->toBe(WaitlistStatus::Waiting);
});

it('expires active waitlist entries when the event is canceled', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 1);
    $waiting = WaitlistEntry::factory()->forEvent($event)->position(1)->create();
    $offered = WaitlistEntry::factory()->forEvent($event)->position(2)->offered(now()->addDay()->toDateTimeString())->create();

    app(CancelEvent::class)($event, applyToFollowing: false);

    expect($waiting->fresh()->status)->toBe(WaitlistStatus::Expired)
        ->and($offered->fresh()->status)->toBe(WaitlistStatus::Expired)
        ->and($event->fresh()->status)->toBe(EventStatus::Canceled);
});

it('expires waitlist entries across a canceled series with "this and following"', function () {
    $tenant = wlTenant();
    $service = wlService($tenant);
    $first = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id, 'capacity' => 1, 'booked_count' => 1,
        'waitlist_enabled' => true, 'series_id' => '11111111-1111-1111-1111-111111111111',
        'starts_at' => '2026-09-01 10:00:00', 'ends_at' => '2026-09-01 11:00:00',
    ]);
    $later = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id, 'capacity' => 1, 'booked_count' => 1,
        'waitlist_enabled' => true, 'series_id' => '11111111-1111-1111-1111-111111111111',
        'starts_at' => '2026-09-08 10:00:00', 'ends_at' => '2026-09-08 11:00:00',
    ]);
    $firstWaiter = WaitlistEntry::factory()->forEvent($first)->position(1)->create();
    $laterWaiter = WaitlistEntry::factory()->forEvent($later)->position(1)->create();

    app(CancelEvent::class)($first, applyToFollowing: true);

    expect($firstWaiter->fresh()->status)->toBe(WaitlistStatus::Expired)
        ->and($laterWaiter->fresh()->status)->toBe(WaitlistStatus::Expired)
        ->and($later->fresh()->status)->toBe(EventStatus::Canceled);
});

// --- Tenant isolation ---

it('scopes waitlist entries to the current tenant', function () {
    $tenantA = wlTenant();
    $serviceA = wlService($tenantA);
    $eventA = wlEvent($tenantA, $serviceA, capacity: 1);
    app(JoinWaitlist::class)($eventA, wlCustomer($tenantA)->id);

    // Switch to a second tenant — tenant A's entry must be invisible.
    $tenantB = wlTenant();

    expect(WaitlistEntry::count())->toBe(0);
    app(TenantManager::class)->forget();
    expect(WaitlistEntry::withoutGlobalScopes()->count())->toBe(1);
});

// --- HTTP endpoint ---

it('lets an admin waitlist a customer via the endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 1);
    $customer = wlCustomer($tenant);
    $admin = wlAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings/waitlist'), [
            'event_id' => $event->id, 'customer_id' => $customer->id, 'party_size' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('waitlist_entries', [
        'tenant_id' => $tenant->id, 'event_id' => $event->id, 'customer_id' => $customer->id, 'status' => 'waiting',
    ]);
});

it('rejects a waitlist join when the feature is disabled', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::Waitlist, 'enabled' => false]);
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 1);
    $customer = wlCustomer($tenant);
    $admin = wlAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings/waitlist'), [
            'event_id' => $event->id, 'customer_id' => $customer->id, 'party_size' => 1,
        ])
        ->assertSessionHasErrors('waitlist');
});

it('rejects a waitlist join for an event that still has room', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    $service = wlService($tenant);
    $event = wlEvent($tenant, $service, capacity: 5, bookedCount: 0);
    $customer = wlCustomer($tenant);
    $admin = wlAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings/waitlist'), [
            'event_id' => $event->id, 'customer_id' => $customer->id, 'party_size' => 1,
        ])
        ->assertSessionHasErrors('waitlist');
});

it('forbids waitlisting without booking.create (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($user)
        ->post(tenantHost('acme', '/bookings/waitlist'), ['event_id' => 1, 'customer_id' => 1, 'party_size' => 1])
        ->assertForbidden();
});

it('rejects an event from another tenant in the waitlist endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = wlAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    $foreignEvent = wlEvent($other, wlService($other), capacity: 1);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings/waitlist'), [
            'event_id' => $foreignEvent->id, 'customer_id' => wlCustomer($tenant)->id, 'party_size' => 1,
        ])
        ->assertSessionHasErrors('event_id');
});
