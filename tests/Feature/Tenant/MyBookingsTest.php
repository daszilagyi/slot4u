<?php

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 00:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** Active tenant (24h cancellation deadline), current + team context. */
function myTenant(): Tenant
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'settings' => ['cancellation_deadline_hours' => 24],
    ]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function customerUser(Tenant $tenant, array $attributes = []): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create([...$attributes, 'tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

it('lists only the customer\'s own bookings, split into upcoming and past', function () {
    $tenant = myTenant();
    $me = customerUser($tenant);
    $other = customerUser($tenant);

    $upcoming = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-03 10:00:00', '2026-09-03 11:00:00')
        ->create(['customer_id' => $me->id]);
    $past = Booking::factory()->forTenant($tenant)->status(BookingStatus::Completed)
        ->startingAt('2026-08-01 10:00:00', '2026-08-01 11:00:00')
        ->create(['customer_id' => $me->id]);
    $foreign = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-04 10:00:00', '2026-09-04 11:00:00')
        ->create(['customer_id' => $other->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/bookings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/My/Bookings')
            ->has('upcoming', 1)
            ->where('upcoming.0.code', $upcoming->code)
            ->where('upcoming.0.can_cancel', true)
            ->has('past', 1)
            ->where('past.0.code', $past->code)
            ->where('past.0.can_cancel', false));

    // The other customer's booking never appears in either bucket.
    expect([$upcoming->code, $past->code])->not->toContain($foreign->code);
});

it('cancels the customer\'s own booking outside the deadline', function () {
    $tenant = myTenant();
    $me = customerUser($tenant);
    $booking = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-03 10:00:00', '2026-09-03 11:00:00')
        ->create(['customer_id' => $me->id]);

    $this->actingAs($me)
        ->post(tenantHost('acme', "/my/bookings/{$booking->id}/cancel"))
        ->assertRedirect(tenantHost('acme', '/my/bookings'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled);
});

it('refuses to cancel the customer\'s booking inside the deadline', function () {
    $tenant = myTenant();
    $me = customerUser($tenant);
    // Starts in 10h → inside the 24h deadline.
    $booking = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-01 10:00:00', '2026-09-01 11:00:00')
        ->create(['customer_id' => $me->id]);

    $this->actingAs($me)
        ->from(tenantHost('acme', '/my/bookings'))
        ->post(tenantHost('acme', "/my/bookings/{$booking->id}/cancel"))
        ->assertSessionHasErrors('cancel');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('404s when cancelling another customer\'s booking', function () {
    $tenant = myTenant();
    $me = customerUser($tenant);
    $other = customerUser($tenant);
    $foreign = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-03 10:00:00', '2026-09-03 11:00:00')
        ->create(['customer_id' => $other->id]);

    $this->actingAs($me)
        ->post(tenantHost('acme', "/my/bookings/{$foreign->id}/cancel"))
        ->assertNotFound();

    expect($foreign->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('downloads the customer\'s own booking as an ics file', function () {
    $tenant = myTenant();
    $me = customerUser($tenant);
    $booking = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-03 10:00:00', '2026-09-03 11:00:00')
        ->create(['customer_id' => $me->id]);

    $response = $this->actingAs($me)
        ->get(tenantHost('acme', "/my/bookings/{$booking->id}/ics"));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    expect($response->getContent())->toContain('BEGIN:VCALENDAR')
        ->toContain($booking->code);
});

it('404s the ics of another customer\'s booking', function () {
    $tenant = myTenant();
    $me = customerUser($tenant);
    $other = customerUser($tenant);
    $foreign = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-03 10:00:00', '2026-09-03 11:00:00')
        ->create(['customer_id' => $other->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', "/my/bookings/{$foreign->id}/ics"))
        ->assertNotFound();
});

it('404s a booking that belongs to another tenant', function () {
    $tenant = myTenant();
    $me = customerUser($tenant);

    // A booking on a different tenant: the BelongsToTenant global scope must 404
    // the route binding before the ownership policy even runs.
    $other = Tenant::factory()->active()->create(['slug' => 'globex']);
    $foreign = Booking::factory()->forTenant($other)
        ->startingAt('2026-09-03 10:00:00', '2026-09-03 11:00:00')->create();

    // Restore the acting tenant's context for the request.
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $this->actingAs($me)
        ->get(tenantHost('acme', "/my/bookings/{$foreign->id}/ics"))
        ->assertNotFound();

    $this->actingAs($me)
        ->post(tenantHost('acme', "/my/bookings/{$foreign->id}/cancel"))
        ->assertNotFound();
});

it('forbids a staff user from the members area', function () {
    $tenant = myTenant();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $staff = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff->assignRole(Role::TenantAdmin->value);

    $this->actingAs($staff)
        ->get(tenantHost('acme', '/my/bookings'))
        ->assertForbidden();
});

it('redirects a guest from the members area to login', function () {
    myTenant();

    $this->get(tenantHost('acme', '/my/bookings'))
        ->assertRedirectContains('/login');
});

it('sends a customer to the members area after login', function () {
    $tenant = myTenant();
    customerUser($tenant, ['email' => 'anna@example.test', 'password' => Hash::make('secret-pw-123')]);

    $this->post(tenantHost('acme', '/login'), [
        'email' => 'anna@example.test',
        'password' => 'secret-pw-123',
    ])->assertRedirect(tenantHost('acme', '/my/bookings'));

    $this->assertAuthenticated();
});
