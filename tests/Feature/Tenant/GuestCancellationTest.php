<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\OnlineCancellation;
use App\Settings\TenantSettings;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Guest self-service cancellation (SLO-129)
|--------------------------------------------------------------------------
|
| A guest books without an account, so the members area is closed to them and
| the only way out used to be a phone call. The confirmation page's code is the
| credential — the same bearer the page itself already runs on.
|
| Two things are worth more attention than the happy path: that the button and
| the endpoint agree (a control offered for something that gets refused is worse
| than no control), and that cancelling is never something a GET can do.
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
 * @return array{0: Tenant, 1: Booking}
 */
function guestCancelFixture(array $settings = [], array $booking = []): array
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'settings' => array_merge([
            'online_cancellation_enabled' => true,
            'cancellation_deadline_hours' => 24,
        ], $settings),
    ]);

    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
    ]);

    $row = Booking::factory()->forTenant($tenant)->create(array_merge([
        'service_id' => $service->id,
        'customer_id' => null,
        'guest_name' => 'Teszt Vendég',
        'guest_email' => 'vendeg@example.test',
        'status' => BookingStatus::Confirmed,
        'starts_at' => Carbon::now()->addDays(5),
        'ends_at' => Carbon::now()->addDays(5)->addHour(),
    ], $booking));

    return [$tenant, $row];
}

it('lets a guest cancel from the confirmation page', function () {
    [$tenant, $booking] = guestCancelFixture();

    $this->assertGuest();

    $this->post(tenantHost('acme', '/booked/'.$booking->code.'/cancel'))
        ->assertRedirect();

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled);
});

it('offers the button only when the endpoint would accept it', function () {
    [$tenant, $booking] = guestCancelFixture();

    $this->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Booked')
            ->where('booking.can_cancel', true));
});

it('refuses a cancellation inside the notice period', function () {
    // 24 hours' notice, and the booking starts in two.
    [$tenant, $booking] = guestCancelFixture(booking: [
        'starts_at' => Carbon::now()->addHours(2),
        'ends_at' => Carbon::now()->addHours(3),
    ]);

    $this->from(tenantHost('acme', '/booked/'.$booking->code))
        ->post(tenantHost('acme', '/booked/'.$booking->code.'/cancel'))
        ->assertSessionHasErrors('cancel');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('hides the button inside the notice period', function () {
    [$tenant, $booking] = guestCancelFixture(booking: [
        'starts_at' => Carbon::now()->addHours(2),
        'ends_at' => Carbon::now()->addHours(3),
    ]);

    $this->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertInertia(fn ($page) => $page->where('booking.can_cancel', false));
});

it('refuses everything when the tenant switched online cancellation off', function () {
    // The switch the deadline could never express: zero hours means "up to the
    // moment it starts", which is the most permissive setting, not the strictest.
    [$tenant, $booking] = guestCancelFixture(['online_cancellation_enabled' => false]);

    $this->from(tenantHost('acme', '/booked/'.$booking->code))
        ->post(tenantHost('acme', '/booked/'.$booking->code.'/cancel'))
        ->assertSessionHasErrors('cancel');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $this->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertInertia(fn ($page) => $page->where('booking.can_cancel', false));
});

it('says which of the two reasons applied', function () {
    // "We do not take online cancellations" and "you are too late" send a person
    // to different places; telling them the wrong one wastes a phone call.
    [$tenant, $booking] = guestCancelFixture(['online_cancellation_enabled' => false]);
    $settings = TenantSettings::fromArray($tenant->settings);

    expect(OnlineCancellation::refusal($booking, $settings))
        ->toBe(OnlineCancellation::REFUSED_DISABLED);

    $late = TenantSettings::fromArray(['cancellation_deadline_hours' => 240]);

    expect(OnlineCancellation::refusal($booking, $late))
        ->toBe(OnlineCancellation::REFUSED_DEADLINE);
});

it('refuses a booking that is already in a terminal state', function () {
    [$tenant, $booking] = guestCancelFixture(booking: ['status' => BookingStatus::Canceled]);

    $this->from(tenantHost('acme', '/booked/'.$booking->code))
        ->post(tenantHost('acme', '/booked/'.$booking->code.'/cancel'))
        ->assertSessionHasErrors('cancel');
});

it('404s on another tenant booking code', function () {
    guestCancelFixture();

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    $foreign = Booking::factory()->forTenant($other)->create([
        'status' => BookingStatus::Confirmed,
        'starts_at' => Carbon::now()->addDays(5),
    ]);
    app(TenantManager::class)->forget();

    $this->post(tenantHost('acme', '/booked/'.$foreign->code.'/cancel'))
        ->assertNotFound();

    expect($foreign->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('⚠️ cannot be triggered by a GET', function () {
    // The design constraint this feature was built around: a cancel that a GET
    // performs is executed by every corporate mail scanner and link-preview bot
    // that walks the confirmation email. Bookings would cancel themselves before
    // anyone read the message.
    [$tenant, $booking] = guestCancelFixture();

    $this->get(tenantHost('acme', '/booked/'.$booking->code.'/cancel'))
        ->assertMethodNotAllowed();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('applies the same switch to a signed-in customer', function () {
    // One rule for both surfaces. A tenant that turned cancellation off must not
    // find it still working for anyone who happens to have an account.
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'settings' => ['online_cancellation_enabled' => false, 'cancellation_deadline_hours' => 24],
    ]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $customer = User::factory()->create(['tenant_id' => $tenant->id]);
    $customer->assignRole(Role::Customer->value);

    $service = Service::factory()->forTenant($tenant)->create();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'starts_at' => Carbon::now()->addDays(5),
        'ends_at' => Carbon::now()->addDays(5)->addHour(),
    ]);

    $this->actingAs($customer)
        ->get(tenantHost('acme', '/my/bookings'))
        ->assertInertia(fn ($page) => $page->where('upcoming.0.can_cancel', false));

    $this->actingAs($customer)
        ->from(tenantHost('acme', '/my/bookings'))
        ->post(tenantHost('acme', '/my/bookings/'.$booking->id.'/cancel'))
        ->assertSessionHasErrors('cancel');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('leaves cancellation on for a tenant that never configured it', function () {
    // The default matters: a booking system a customer cannot get out of is a
    // worse default than one they can.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'settings' => null]);
    app(TenantManager::class)->set($tenant);

    expect(TenantSettings::fromArray($tenant->settings)->onlineCancellationEnabled)->toBeTrue();
});
