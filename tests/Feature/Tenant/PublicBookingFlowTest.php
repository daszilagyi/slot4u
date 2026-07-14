<?php

use App\Actions\Customer\CreateCustomer;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Customer creation assigns the `customer` role (FindOrCreateCustomer).
    $this->seed(PermissionSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * A duration_based service (60 min) with one assignable staff member and a Monday
 * 09:00–17:00 band, so 2026-09-07 09:00 local (= 07:00Z) is a real, bookable slot
 * that store() will accept when re-validating against AvailabilityService.
 *
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function flowService(array $serviceOverrides = []): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
    ], $serviceOverrides));
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(Carbon::parse('2026-09-07')->isoWeekday(), '09:00', '17:00')
        ->create();

    return [$tenant, $service, $staff];
}

/**
 * @return array<string, mixed>
 */
function bookingPayload(Service $service, Staff $staff, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07T07:00:00Z',
        'ends_at' => '2026-09-07T08:00:00Z',
        'name' => 'Teszt Vendég',
        'email' => 'guest@example.test',
        'phone' => '+3611234567',
    ], $overrides);
}

function tenantBookings(Tenant $tenant)
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id);
}

it('creates a confirmed booking for a guest and auto-creates the customer', function () {
    [$tenant, $service, $staff] = flowService();

    $this->post(tenantHost('acme', '/book'), bookingPayload($service, $staff))
        ->assertRedirect();

    $booking = tenantBookings($tenant)->first();
    expect($booking)->not->toBeNull()
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->source)->toBe(BookingSource::Online);

    // The guest became a customer of this tenant, linked to the booking. (The
    // customer-role assignment itself is covered by the SLO-84 CustomerTest.)
    $customer = Customer::withoutGlobalScopes()->where('email', 'guest@example.test')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->tenant_id)->toBe($tenant->id)
        ->and($booking->customer_id)->toBe($customer->id);
});

it('reuses an existing customer by email without duplicating', function () {
    [$tenant, $service, $staff] = flowService();
    // Seed an existing customer of this tenant.
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(CreateCustomer::class)(['name' => 'Régi', 'email' => 'guest@example.test', 'phone' => null]);
    app(TenantManager::class)->forget();

    $this->post(tenantHost('acme', '/book'), bookingPayload($service, $staff))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(User::withoutGlobalScopes()->where('email', 'guest@example.test')->count())->toBe(1);
});

it('surfaces a slot-unavailable error when the slot is already taken', function () {
    [$tenant, $service, $staff] = flowService();
    // An existing confirmed booking occupying the same staff + slot.
    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'booking_mode' => BookingMode::DurationBased,
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-07 07:00:00',
        'ends_at' => '2026-09-07 08:00:00',
    ]);

    $this->post(tenantHost('acme', '/book'), bookingPayload($service, $staff))
        ->assertSessionHasErrors('booking');

    // Only the pre-existing booking exists — the clashing attempt created nothing.
    expect(tenantBookings($tenant)->count())->toBe(1);
});

it('rejects a submitted time that is not a real available slot (off-grid)', function () {
    [$tenant, $service, $staff] = flowService();

    // 01:00Z = 03:00 local, outside the 09:00–17:00 band → not a real slot.
    $this->post(tenantHost('acme', '/book'), bookingPayload($service, $staff, [
        'starts_at' => '2026-09-07T01:00:00Z',
        'ends_at' => '2026-09-07T02:00:00Z',
    ]))->assertSessionHasErrors('booking');

    expect(tenantBookings($tenant)->count())->toBe(0);
});

it('creates a requested (approval-pending) booking for an approval-required service', function () {
    [$tenant, $service, $staff] = flowService(['requires_approval' => true]);

    $this->post(tenantHost('acme', '/book'), bookingPayload($service, $staff))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(tenantBookings($tenant)->first()->status)->toBe(BookingStatus::Requested);
});

it('creates a pending_payment booking for an online-payment service', function () {
    [$tenant, $service, $staff] = flowService(['online_payment_required' => true]);

    $this->post(tenantHost('acme', '/book'), bookingPayload($service, $staff))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(tenantBookings($tenant)->first()->status)->toBe(BookingStatus::PendingPayment);
});

it('rejects a service_id that belongs to another tenant', function () {
    [$tenant, $service, $staff] = flowService();
    // A real, active service — but of a different tenant. The tenant-anchored
    // Rule::exists must refuse it under acme's subdomain (defense-in-depth over
    // the slot re-validation, per the "tenant-izoláció igazolt" DoD).
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignService = Service::factory()->forTenant($other)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
    ]);

    $this->post(tenantHost('acme', '/book'), bookingPayload($service, $staff, [
        'service_id' => $foreignService->id,
    ]))->assertSessionHasErrors('service_id');

    expect(tenantBookings($tenant)->count())->toBe(0)
        ->and(tenantBookings($other)->count())->toBe(0);
});

it('rejects an email that already belongs to another account', function () {
    [$tenant, $service, $staff] = flowService();
    // Same email owned by another tenant's user (global unique email, MVP auth).
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    User::factory()->create(['tenant_id' => $other->id, 'email' => 'guest@example.test']);

    $this->post(tenantHost('acme', '/book'), bookingPayload($service, $staff))
        ->assertSessionHasErrors('email');

    expect(tenantBookings($tenant)->count())->toBe(0);
});

it('shows the confirmation page by code and 404s a cross-tenant code', function () {
    [$tenant, $service, $staff] = flowService();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->get(tenantHost('acme', "/booked/{$booking->code}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Booked')
            ->where('booking.code', $booking->code)
            ->where('booking.status', 'confirmed'));

    // A different tenant cannot resolve this code.
    Tenant::factory()->active()->create(['slug' => 'other']);
    $this->get(tenantHost('other', "/booked/{$booking->code}"))->assertNotFound();
});

it('still resolves the permanent confirmation link after the booking is canceled', function () {
    // The /booked/{code} link never expires, so a later cancel/reject must still
    // render the page — the frontend maps the status to its own message (SLO-93).
    [$tenant, $service, $staff] = flowService();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'status' => BookingStatus::Canceled,
    ]);

    $this->get(tenantHost('acme', "/booked/{$booking->code}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Booked')
            ->where('booking.status', 'canceled'));
});

it('downloads the booking as an ics calendar file', function () {
    [$tenant, $service, $staff] = flowService();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 07:00:00',
        'ends_at' => '2026-09-07 08:00:00',
    ]);

    $response = $this->get(tenantHost('acme', "/booked/{$booking->code}/ics"));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/calendar');
    expect($response->getContent())
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('BEGIN:VEVENT')
        ->toContain('DTSTART:20260907T070000Z');
});
