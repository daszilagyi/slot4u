<?php

use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

// tenantHost() lives in tests/Pest.php.

/**
 * A no_time_slot service of the `acme` tenant.
 *
 * @return array{0: Tenant, 1: Service}
 */
function orderService(?string $fulfillment = 'digital', array $serviceOverrides = []): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::NoTimeSlot)->create(array_merge([
        'active' => true,
        'duration_minutes' => null,
        'requires_staff' => false,
        'settings' => $fulfillment === null ? null : ['fulfillment_type' => $fulfillment],
    ], $serviceOverrides));

    return [$tenant, $service];
}

/**
 * @return array<string, mixed>
 */
function orderGuest(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
        'phone' => '+36301234567',
        'notes' => 'Kérem PDF-ben.',
    ], $overrides);
}

it('shows the order form (not a slot picker) for a no_time_slot service', function () {
    [, $service] = orderService('downloadable');

    $this->get(tenantHost('acme', '/book?service='.$service->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Book')
            ->where('service.booking_mode', 'no_time_slot')
            ->where('service.fulfillment_type', 'downloadable')
            // No slot machinery is computed for this mode.
            ->missing('slots')
            ->missing('week'));
});

it('does not leak a fulfillment type onto a time-slot service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->create([
        'active' => true,
        'settings' => ['fulfillment_type' => 'digital'],
    ]);

    $this->get(tenantHost('acme', '/book?service='.$service->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('service.fulfillment_type', null));
});

it('completes a digital order on the spot and creates the guest customer', function () {
    [$tenant, $service] = orderService('digital');

    $response = $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $service->id]));

    $booking = Booking::query()->firstOrFail();
    $response->assertRedirect('/booked/'.$booking->code);

    // docs/04 §1: a confirmed digital order is delivered instantly → completed.
    expect($booking->status)->toBe(BookingStatus::Completed)
        ->and($booking->booking_mode)->toBe(BookingMode::NoTimeSlot)
        ->and($booking->source)->toBe(BookingSource::Online)
        ->and($booking->starts_at)->toBeNull()
        ->and($booking->ends_at)->toBeNull()
        ->and($booking->notes)->toBe('Kérem PDF-ben.');

    $customer = Customer::query()->where('email', 'anna@example.test')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->tenant_id)->toBe($tenant->id)
        ->and($booking->customer_id)->toBe($customer->id);
});

it('leaves a manual order confirmed for an admin to complete', function () {
    [, $service] = orderService('manual');

    $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $service->id]))
        ->assertStatus(302);

    expect(Booking::query()->firstOrFail()->status)->toBe(BookingStatus::Confirmed);
});

it('leaves a downloadable order confirmed', function () {
    [, $service] = orderService('downloadable');

    $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $service->id]))
        ->assertStatus(302);

    expect(Booking::query()->firstOrFail()->status)->toBe(BookingStatus::Confirmed);
});

it('routes an approval-gated order to requested even when digital', function () {
    [, $service] = orderService('digital', ['requires_approval' => true]);

    $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $service->id]))
        ->assertStatus(302);

    // The approval gate wins over instant digital delivery: nothing is delivered
    // until an admin approves (docs/04 §5).
    expect(Booking::query()->firstOrFail()->status)->toBe(BookingStatus::Requested);
});

it('routes a payment-gated order to pending_payment', function () {
    [, $service] = orderService('digital', ['online_payment_required' => true]);

    $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $service->id]))
        ->assertStatus(302);

    expect(Booking::query()->firstOrFail()->status)->toBe(BookingStatus::PendingPayment);
});

it('404s when the service is not a no_time_slot service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->create(['active' => true]);

    $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $service->id]))
        ->assertNotFound();

    expect(Booking::query()->count())->toBe(0);
});

it('refuses an order for an inactive service', function () {
    [, $service] = orderService('digital', ['active' => false]);

    $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $service->id]))
        ->assertSessionHasErrors('service_id');

    expect(Booking::query()->count())->toBe(0);
});

it('refuses an order for another tenant\'s service', function () {
    orderService('digital');
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = Service::factory()->forTenant($other)->mode(BookingMode::NoTimeSlot)->create(['active' => true]);

    $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $foreign->id]))
        ->assertSessionHasErrors('service_id');

    expect(Booking::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('validates the guest contact details', function () {
    [, $service] = orderService('digital');

    $this->post(tenantHost('acme', '/order'), ['service_id' => $service->id, 'email' => 'not-an-email'])
        ->assertSessionHasErrors(['name', 'email']);

    expect(Booking::query()->count())->toBe(0);
});

it('confirms the order without an appointment time', function () {
    [, $service] = orderService('manual');

    $this->post(tenantHost('acme', '/order'), orderGuest(['service_id' => $service->id]));
    $booking = Booking::query()->firstOrFail();

    $this->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Booked')
            ->where('booking.status', 'confirmed')
            // The confirmation page hides the "when" row / calendar button on null.
            ->where('booking.starts_local', null)
            ->where('booking.starts_at', null));
});

it('404s the ics download for a booking with no appointment time', function () {
    [$tenant, $service] = orderService('manual');
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'booking_mode' => BookingMode::NoTimeSlot,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    // Without this guard the builder emits an empty DTSTART → a malformed .ics.
    $this->get(tenantHost('acme', '/booked/'.$booking->code.'/ics'))->assertNotFound();
});

it('still serves the ics download for a scheduled booking', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->create(['active' => true]);
    $booking = Booking::factory()->forTenant($tenant)->create(['service_id' => $service->id]);

    $this->get(tenantHost('acme', '/booked/'.$booking->code.'/ics'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
});
