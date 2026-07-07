<?php

use App\Actions\Booking\CompleteBooking;
use App\Actions\Booking\CreateBooking;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
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

function ntsTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);

    return $tenant;
}

function ntsService(Tenant $tenant, string $fulfillment, array $overrides = []): Service
{
    return Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::NoTimeSlot,
        'duration_minutes' => null,
        'requires_staff' => false,
        'requires_approval' => false,
        'online_payment_required' => false,
        'price_minor' => 300000,
        'currency' => 'HUF',
        'settings' => ['fulfillment_type' => $fulfillment],
    ], $overrides));
}

function ntsAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

// --- Creation + fulfilment ---

it('auto-completes a confirmed digital no_time_slot booking on creation', function () {
    $tenant = ntsTenant();
    $service = ntsService($tenant, 'digital');

    $booking = app(CreateBooking::class)($service, ['source' => 'online']);

    expect($booking->status)->toBe(BookingStatus::Completed)
        ->and($booking->starts_at)->toBeNull();

    // History records confirmed → completed (the instant delivery).
    $trail = $booking->statusHistory()->orderBy('id')->pluck('to_status')
        ->map(fn ($s) => $s instanceof BookingStatus ? $s->value : $s)->all();
    expect($trail)->toBe(['confirmed', 'completed']);
});

it('leaves a manual no_time_slot booking confirmed for the admin to close', function () {
    $tenant = ntsTenant();
    $service = ntsService($tenant, 'manual');

    $booking = app(CreateBooking::class)($service, ['source' => 'online']);

    expect($booking->status)->toBe(BookingStatus::Confirmed);
});

it('leaves a downloadable no_time_slot booking confirmed (not auto-completed)', function () {
    $tenant = ntsTenant();
    $service = ntsService($tenant, 'downloadable');

    $booking = app(CreateBooking::class)($service, ['source' => 'online']);

    expect($booking->status)->toBe(BookingStatus::Confirmed);
});

it('does not auto-complete a digital booking still awaiting payment', function () {
    $tenant = ntsTenant();
    $service = ntsService($tenant, 'digital', ['online_payment_required' => true]);

    // online_payment_required → pending_payment, so the digital instant-delivery
    // path does not fire until it is confirmed.
    $booking = app(CreateBooking::class)($service, ['source' => 'online']);

    expect($booking->status)->toBe(BookingStatus::PendingPayment);
});

it('completes a manual no_time_slot booking through CompleteBooking', function () {
    $tenant = ntsTenant();
    $service = ntsService($tenant, 'manual');
    $actor = ntsAdmin($tenant);
    $booking = app(CreateBooking::class)($service, ['source' => 'online']);

    app(CompleteBooking::class)($booking, $actor);

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

it('refuses to complete a non no_time_slot booking', function () {
    $tenant = ntsTenant();
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60, 'requires_staff' => true,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, [
        'staff_id' => $staff->id, 'starts_at' => '2026-09-07 10:00:00', 'ends_at' => '2026-09-07 11:00:00', 'source' => 'admin',
    ]);

    expect(fn () => app(CompleteBooking::class)($booking, ntsAdmin($tenant)))
        ->toThrow(ValidationException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

// --- HTTP ---

it('lets an admin complete a no_time_slot booking via the endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = ntsService($tenant, 'manual');
    $booking = app(CreateBooking::class)($service, ['source' => 'online']);
    $admin = ntsAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/complete"))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

it('returns a 422 when completing a non no_time_slot booking via the endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60, 'requires_staff' => true,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $booking = app(CreateBooking::class)($service, [
        'staff_id' => $staff->id, 'starts_at' => '2026-09-07 10:00:00', 'ends_at' => '2026-09-07 11:00:00', 'source' => 'admin',
    ]);
    $admin = ntsAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/complete"))
        ->assertSessionHasErrors('booking');
});

it('forbids completing without booking.edit (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = ntsService($tenant, 'manual');
    $booking = app(CreateBooking::class)($service, ['source' => 'online']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $this->actingAs($user)
        ->post(tenantHost('acme', "/bookings/{$booking->id}/complete"))
        ->assertForbidden();
});

it('scopes completion to the current tenant (cross-tenant 404)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $admin = ntsAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    $service = ntsService($other, 'manual');
    $foreign = app(CreateBooking::class)($service, ['source' => 'online']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$foreign->id}/complete"))
        ->assertNotFound();
});
