<?php

use App\Enums\BookingMode;
use App\Models\Booking;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| "I need an invoice" on the public form (SLO-168)
|--------------------------------------------------------------------------
|
| A receipt is issued by default and needs no address; the Áfa tv. 169. § e)
| requires one on an INVOICE and on nothing else. So the address is collected
| only from the buyers who ask for one — anything else would gather personal
| data most bookings never need.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function billingFlowService(): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
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

    return [$tenant, $service, $staff];
}

/**
 * @return array<string, mixed>
 */
function billingPayload(Service $service, Staff $staff, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07T07:00:00Z',
        'ends_at' => '2026-09-07T08:00:00Z',
        'name' => 'Teszt Vendég',
        'email' => 'vendeg@example.test',
        'phone' => '+3611234567',
    ], $overrides);
}

it('stores nothing billing-related for an ordinary booking', function () {
    // The default path. Most bookings never touch any of this.
    [$tenant, $service, $staff] = billingFlowService();

    $this->post(tenantHost('acme', '/book'), billingPayload($service, $staff))
        ->assertRedirect();

    $booking = Booking::withoutGlobalScopes()->sole();

    expect($booking->wants_invoice)->toBeFalse()
        ->and($booking->billing_city)->toBeNull()
        ->and($booking->billing_address)->toBeNull();
});

it('records the address when the buyer asked for an invoice', function () {
    [$tenant, $service, $staff] = billingFlowService();

    $this->post(tenantHost('acme', '/book'), billingPayload($service, $staff, [
        'wants_invoice' => true,
        'billing_name' => 'Céges Vevő Kft.',
        'billing_tax_number' => '12345678-2-42',
        'billing_post_code' => '1051',
        'billing_city' => 'Budapest',
        'billing_address' => 'Példa utca 1.',
    ]))->assertRedirect();

    $booking = Booking::withoutGlobalScopes()->sole();

    expect($booking->wants_invoice)->toBeTrue()
        ->and($booking->billing_name)->toBe('Céges Vevő Kft.')
        ->and($booking->billing_tax_number)->toBe('12345678-2-42')
        ->and($booking->billing_post_code)->toBe('1051')
        ->and($booking->billing_city)->toBe('Budapest')
        ->and($booking->billing_address)->toBe('Példa utca 1.')
        // Defaulted rather than demanded: a missing country has one obvious
        // answer, unlike a missing street.
        ->and($booking->billing_country_code)->toBe('HU');
});

it('refuses an invoice request with half an address', function () {
    // Refused at the form, not silently downgraded to a receipt: the buyer asked
    // for a document they would not have received.
    [$tenant, $service, $staff] = billingFlowService();

    $this->from(tenantHost('acme', '/book'))
        ->post(tenantHost('acme', '/book'), billingPayload($service, $staff, [
            'wants_invoice' => true,
            'billing_name' => 'Céges Vevő Kft.',
            'billing_post_code' => '1051',
            // no city, no street
        ]))
        ->assertSessionHasErrors(['billing_city', 'billing_address']);

    expect(Booking::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps no address from a form where the box was unticked', function () {
    // A ticked-then-unticked box leaves values in the DOM. Storing them would be
    // personal data kept for a document nobody asked for.
    [$tenant, $service, $staff] = billingFlowService();

    $this->post(tenantHost('acme', '/book'), billingPayload($service, $staff, [
        'wants_invoice' => false,
        'billing_name' => 'Meggondolta Magát Kft.',
        'billing_post_code' => '1051',
        'billing_city' => 'Budapest',
        'billing_address' => 'Példa utca 1.',
    ]))->assertRedirect();

    $booking = Booking::withoutGlobalScopes()->sole();

    expect($booking->wants_invoice)->toBeFalse()
        ->and($booking->billing_name)->toBeNull()
        ->and($booking->billing_address)->toBeNull();
});

it('carries the request through a no-time-slot order too', function () {
    // The same money, the same obligation — the order flow must not be the one
    // entry point that quietly forgets.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::NoTimeSlot,
        'active' => true,
    ]);

    $this->post(tenantHost('acme', '/order'), [
        'service_id' => $service->id,
        'name' => 'Teszt Vendég',
        'email' => 'vendeg@example.test',
        'phone' => '+3611234567',
        'wants_invoice' => true,
        'billing_name' => 'Céges Vevő Kft.',
        'billing_post_code' => '1051',
        'billing_city' => 'Budapest',
        'billing_address' => 'Példa utca 1.',
    ])->assertRedirect();

    expect(Booking::withoutGlobalScopes()->sole()->wants_invoice)->toBeTrue();
});
