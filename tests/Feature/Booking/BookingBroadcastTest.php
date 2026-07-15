<?php

use App\Broadcasting\TenantBookingChannel;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

// The live admin booking feed (SLO-117): BookingCreated broadcasts to the tenant's
// private channel, and only that tenant's staff may subscribe.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    // BROADCAST_CONNECTION stays "null" (phpunit.xml), so creating a booking is a
    // no-op broadcast — the channel authorization is exercised directly through
    // TenantBookingChannel::join() rather than the signing HTTP endpoint.
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function broadcastStaff(Tenant $tenant, Role $role = Role::TenantAdmin): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

it('is a broadcast event on the tenant private channel with a safe payload', function () {
    $tenant = Tenant::factory()->active()->create();
    app(TenantManager::class)->set($tenant);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Teszt Elek']);
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Masszázs']);
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'code' => 'ABC123',
        'status' => BookingStatus::Confirmed,
        'source' => BookingSource::Online,
        'starts_at' => '2026-09-01 08:00:00',
    ]);

    $event = new BookingCreated($booking);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event->broadcastOn())->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()->name)->toBe('private-tenant.'.$tenant->id.'.bookings')
        ->and($event->broadcastAs())->toBe('booking.created');

    $payload = $event->broadcastWith();
    expect($payload)->toMatchArray([
        'id' => $booking->id,
        'code' => 'ABC123',
        'customer_name' => 'Teszt Elek',
        'service_name' => 'Masszázs',
        'status' => 'confirmed',
        'source' => 'online',
    ])->and($payload['starts_at'])->toStartWith('2026-09-01T08:00:00');
});

it('dispatches BookingCreated when a booking is created', function () {
    Event::fake([BookingCreated::class]);

    $tenant = Tenant::factory()->active()->create();
    $booking = Booking::factory()->forTenant($tenant)->create();

    Event::assertDispatched(BookingCreated::class, fn (BookingCreated $e) => $e->booking->is($booking));
});

it('authorizes a same-tenant staff member on the bookings channel', function () {
    $tenant = Tenant::factory()->active()->create();
    $staff = broadcastStaff($tenant);

    expect((new TenantBookingChannel)->join($staff->fresh(), (string) $tenant->id))->toBeTrue();
});

it('denies a customer of the same tenant', function () {
    $tenant = Tenant::factory()->active()->create();
    $customer = broadcastStaff($tenant, Role::Customer);

    expect((new TenantBookingChannel)->join($customer->fresh(), (string) $tenant->id))->toBeFalse();
});

it('denies a staff member of another tenant', function () {
    $tenantA = Tenant::factory()->active()->create();
    $tenantB = Tenant::factory()->active()->create();
    $staffB = broadcastStaff($tenantB);

    expect((new TenantBookingChannel)->join($staffB->fresh(), (string) $tenantA->id))->toBeFalse();
});
