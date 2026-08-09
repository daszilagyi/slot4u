<?php

use App\Enums\BookingMode;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Location;
use App\Models\QuoteRequest;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every form that accepts a phone number rejects text that is not one, and stores
 * what it accepts in E.164 (SLO-151).
 *
 * Daniel found `sfsdfsdfsd` sitting in a real booking on demo.slot4u.hu: the field
 * was `['nullable', 'string', 'max:50']` at all nine entry points, which is no
 * format check at all. This file walks all nine, because the fix is only worth
 * anything if none of them was missed.
 *
 * tenantHost() lives in tests/Pest.php.
 */

/** What a visitor types when they cannot be bothered — the value from production. */
const JUNK_PHONE = 'sfsdfsdfsd';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 08:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function phoneTenant(array $overrides = []): Tenant
{
    return Tenant::factory()->active()->create([...$overrides, 'slug' => 'acme']);
}

/** A tenant-admin, for the three admin-side forms. */
function phoneAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);

    return $user;
}

// --- 1. public time-slot booking -------------------------------------------

/** @return array{0: Tenant, 1: array<string, mixed>} */
function phoneBookingFixture(): array
{
    $tenant = phoneTenant();
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

    return [$tenant, [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07T07:00:00Z',
        'ends_at' => '2026-09-07T08:00:00Z',
        'name' => 'Teszt Vendég',
        'email' => 'guest@example.test',
    ]];
}

it('rejects a non-number on the public booking form', function () {
    [, $payload] = phoneBookingFixture();

    $this->post(tenantHost('acme', '/book'), [...$payload, 'phone' => JUNK_PHONE])
        ->assertSessionHasErrors('phone');

    expect(Booking::withoutGlobalScopes()->count())->toBe(0);
});

it('stores a nationally typed number from the public booking form in E.164', function () {
    [$tenant, $payload] = phoneBookingFixture();

    $this->post(tenantHost('acme', '/book'), [...$payload, 'phone' => '06 30/123-4567'])
        ->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->contactPhone())
        ->toBe('+36301234567');
});

// --- 2. public no_time_slot order ------------------------------------------

/** @return array{0: Tenant, 1: array<string, mixed>} */
function phoneOrderFixture(): array
{
    $tenant = phoneTenant();
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::NoTimeSlot)->create([
        'active' => true,
        'duration_minutes' => null,
        'requires_staff' => false,
        'settings' => ['fulfillment_type' => 'digital'],
    ]);

    return [$tenant, [
        'service_id' => $service->id,
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
    ]];
}

it('rejects a non-number on the public order form', function () {
    [, $payload] = phoneOrderFixture();

    $this->post(tenantHost('acme', '/order'), [...$payload, 'phone' => JUNK_PHONE])
        ->assertSessionHasErrors('phone');
});

it('stores an order phone in E.164', function () {
    [$tenant, $payload] = phoneOrderFixture();

    $this->post(tenantHost('acme', '/order'), [...$payload, 'phone' => '+36 30 123 4567'])
        ->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->contactPhone())
        ->toBe('+36301234567');
});

// --- 3. public event sign-up ------------------------------------------------

/** @return array{0: Tenant, 1: Event, 2: array<string, mixed>} */
function phoneEventFixture(): array
{
    $tenant = phoneTenant();
    $service = Service::factory()->forTenant($tenant)->eventBased()->create(['active' => true]);
    $event = Event::factory()->forTenant($tenant)->at('2026-09-10 18:00:00', '2026-09-10 19:00:00')
        ->create(['service_id' => $service->id, 'capacity' => 10]);

    return [$tenant, $event, [
        'party_size' => 2,
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
    ]];
}

it('rejects a non-number on the event sign-up form', function () {
    [, $event, $payload] = phoneEventFixture();

    $this->post(tenantHost('acme', "/events/{$event->id}/book"), [...$payload, 'phone' => JUNK_PHONE])
        ->assertSessionHasErrors('phone');
});

it('stores an event sign-up phone in E.164', function () {
    [$tenant, $event, $payload] = phoneEventFixture();

    $this->post(tenantHost('acme', "/events/{$event->id}/book"), [...$payload, 'phone' => '06301234567'])
        ->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->contactPhone())
        ->toBe('+36301234567');
});

// --- 4. public quote request ------------------------------------------------

/** @return array{0: Tenant, 1: array<string, mixed>} */
function phoneQuoteFixture(): array
{
    $tenant = phoneTenant();
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::QuoteRequest)->create([
        'active' => true,
        'duration_minutes' => null,
        'requires_staff' => false,
        'settings' => ['quote_fields' => ['Létszám']],
    ]);

    return [$tenant, [
        'service_id' => $service->id,
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
        'notes' => 'Előzetes árat kérek.',
        'fields' => ['40 fő'],
    ]];
}

it('rejects a non-number on the quote request form', function () {
    [, $payload] = phoneQuoteFixture();

    $this->post(tenantHost('acme', '/quote'), [...$payload, 'phone' => JUNK_PHONE])
        ->assertSessionHasErrors('phone');
});

it('stores a quote request phone in E.164', function () {
    [$tenant, $payload] = phoneQuoteFixture();

    $this->post(tenantHost('acme', '/quote'), [...$payload, 'phone' => '06 30 123 4567'])
        ->assertSessionHasNoErrors();

    expect(QuoteRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->contactPhone())
        ->toBe('+36301234567');
});

// --- 5. customer registration (validated outside a FormRequest) -------------

it('rejects a non-number on customer registration', function () {
    phoneTenant();

    $this->post(tenantHost('acme', '/register'), [
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
        'phone' => JUNK_PHONE,
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasErrors('phone');

    expect(User::query()->where('email', 'anna@example.test')->exists())->toBeFalse();
});

it('stores a registration phone in E.164', function () {
    phoneTenant();

    $this->post(tenantHost('acme', '/register'), [
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
        'phone' => '06-30-123-4567',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'anna@example.test')->sole()->phone)->toBe('+36301234567');
});

// --- 6. members area profile ------------------------------------------------

function phoneCustomer(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

it('rejects a non-number on the members profile', function () {
    $tenant = phoneTenant();
    $me = phoneCustomer($tenant);

    $this->actingAs($me)
        ->put(tenantHost('acme', '/my/profile'), ['name' => 'Új Név', 'phone' => JUNK_PHONE])
        ->assertSessionHasErrors('phone');
});

it('stores a members profile phone in E.164', function () {
    $tenant = phoneTenant();
    $me = phoneCustomer($tenant);

    $this->actingAs($me)
        ->put(tenantHost('acme', '/my/profile'), ['name' => 'Új Név', 'phone' => '06 20 999 9999'])
        ->assertSessionHasNoErrors();

    expect($me->refresh()->phone)->toBe('+36209999999');
});

// --- 7. admin customer CRUD -------------------------------------------------

it('rejects a non-number when an admin creates a customer', function () {
    $tenant = phoneTenant();

    $this->actingAs(phoneAdmin($tenant))
        ->post(tenantHost('acme', '/customers'), [
            'name' => 'Új Ügyfél',
            'email' => 'brand@example.test',
            'phone' => JUNK_PHONE,
        ])
        ->assertSessionHasErrors('phone');
});

it('stores an admin-entered customer phone in E.164', function () {
    $tenant = phoneTenant();

    $this->actingAs(phoneAdmin($tenant))
        ->post(tenantHost('acme', '/customers'), [
            'name' => 'Új Ügyfél',
            'email' => 'brand@example.test',
            'phone' => '06 1 234 5678',
        ])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'brand@example.test')->sole()->phone)->toBe('+3612345678');
});

// --- 8. tenant company profile ----------------------------------------------

/** @return array<string, mixed> */
function phoneSettingsPayload(array $overrides = []): array
{
    return [...[
        'name' => 'Acme Kft.',
        'cancellation_deadline_hours' => 24,
        'slot_interval_minutes' => 30,
    ], ...$overrides];
}

it('rejects a non-number in the company profile', function () {
    $tenant = phoneTenant();

    $this->actingAs(phoneAdmin($tenant))
        ->post(tenantHost('acme', '/settings'), phoneSettingsPayload(['phone' => JUNK_PHONE]))
        ->assertSessionHasErrors('phone');
});

it('stores the company profile phone in E.164', function () {
    $tenant = phoneTenant();

    $this->actingAs(phoneAdmin($tenant))
        ->post(tenantHost('acme', '/settings'), phoneSettingsPayload(['phone' => '06 1 234 5678']))
        ->assertSessionHasNoErrors();

    expect($tenant->refresh()->settings['phone'])->toBe('+3612345678');
});

// --- 9. location -------------------------------------------------------------

it('rejects a non-number on a location', function () {
    $tenant = phoneTenant();

    $this->actingAs(phoneAdmin($tenant))
        ->post(tenantHost('acme', '/locations'), [
            'name' => 'Downtown',
            'phone' => JUNK_PHONE,
            'active' => true,
        ])
        ->assertSessionHasErrors('phone');
});

it('stores a location phone in E.164', function () {
    $tenant = phoneTenant();

    $this->actingAs(phoneAdmin($tenant))
        ->post(tenantHost('acme', '/locations'), [
            'name' => 'Downtown',
            'phone' => '+36 1 234 5678',
            'active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(Location::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->phone)
        ->toBe('+3612345678');
});

// --- cross-cutting behaviour -------------------------------------------------

it('keeps the field optional: a blank phone is accepted and stored as null', function () {
    [$tenant, $payload] = phoneOrderFixture();

    $this->post(tenantHost('acme', '/order'), [...$payload, 'phone' => '   '])
        ->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->contactPhone())
        ->toBeNull();
});

it('accepts a foreign guest who dials with a country code', function () {
    [$tenant, $payload] = phoneOrderFixture();

    $this->post(tenantHost('acme', '/order'), [...$payload, 'phone' => '+43 664 1234567'])
        ->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->contactPhone())
        ->toBe('+436641234567');
});

it('assumes the tenant own country, not the platform default, for a bare number', function () {
    // An Austrian tenant: 0664 1234567 is Austrian here and nonsense in Hungary.
    $tenant = phoneTenant(['timezone' => 'Europe/Vienna']);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::NoTimeSlot)->create([
        'active' => true,
        'duration_minutes' => null,
        'requires_staff' => false,
        'settings' => ['fulfillment_type' => 'digital'],
    ]);

    $this->post(tenantHost('acme', '/order'), [
        'service_id' => $service->id,
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
        'phone' => '0664 1234567',
    ])->assertSessionHasNoErrors();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->contactPhone())
        ->toBe('+436641234567');
});

it('tells the visitor what a right number looks like instead of only that theirs is wrong', function () {
    [, $payload] = phoneOrderFixture();

    $this->post(tenantHost('acme', '/order'), [...$payload, 'phone' => JUNK_PHONE]);

    // The example is the tenant's own country's — a Hungarian mobile here.
    expect(session('errors')->first('phone'))->toContain('+36 20');
});

it('reports one problem, not two, for junk long enough to also trip max:', function () {
    [, $payload] = phoneOrderFixture();

    $this->post(tenantHost('acme', '/order'), [...$payload, 'phone' => str_repeat('x', 80)]);

    expect(session('errors')->get('phone'))->toHaveCount(1);
});
