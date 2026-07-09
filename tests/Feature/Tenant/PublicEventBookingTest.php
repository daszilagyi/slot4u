<?php

use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\WaitlistEntry;
use Database\Seeders\BasePlanSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 08:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * An event_based service with one future scheduled event.
 *
 * @return array{0: Tenant, 1: Service, 2: Event}
 */
function bookEventService(array $eventOverrides = [], array $serviceOverrides = []): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->eventBased()
        ->create(array_merge(['active' => true], $serviceOverrides));
    $event = Event::factory()->forTenant($tenant)->at('2026-09-10 18:00:00', '2026-09-10 19:00:00')
        ->create(array_merge(['service_id' => $service->id, 'capacity' => 10], $eventOverrides));

    return [$tenant, $service, $event];
}

function eventGuest(array $overrides = []): array
{
    return array_merge([
        'party_size' => 2,
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
        'phone' => '+36301234567',
    ], $overrides);
}

it('lets a guest sign up for an event and claims capacity', function () {
    [, $service, $event] = bookEventService();

    $response = $this->post(tenantHost('acme', "/events/{$event->id}/book"), eventGuest(['party_size' => 2]));

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('/booked/');

    $booking = Booking::query()->where('event_id', $event->id)->sole();
    expect($booking->booking_mode)->toBe(BookingMode::EventBased)
        ->and($booking->party_size)->toBe(2)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->source)->toBe(BookingSource::Online)
        ->and($event->refresh()->booked_count)->toBe(2);
});

it('sends an approval-required event sign-up to requested', function () {
    [, , $event] = bookEventService([], ['requires_approval' => true]);

    $this->post(tenantHost('acme', "/events/{$event->id}/book"), eventGuest(['party_size' => 1]))
        ->assertStatus(302);

    expect(Booking::query()->where('event_id', $event->id)->sole()->status)
        ->toBe(BookingStatus::Requested);
});

it('surfaces a full event on sign-up without creating a booking', function () {
    [, , $event] = bookEventService(['capacity' => 5, 'booked_count' => 5]);

    $this->from(tenantHost('acme', '/book'))
        ->post(tenantHost('acme', "/events/{$event->id}/book"), eventGuest(['party_size' => 1]))
        ->assertSessionHasErrors('party_size');

    expect(Booking::query()->where('event_id', $event->id)->exists())->toBeFalse()
        ->and($event->refresh()->booked_count)->toBe(5);
});

it('joins the waitlist of a full event and shows the position', function () {
    [, , $event] = bookEventService(['capacity' => 5, 'booked_count' => 5, 'waitlist_enabled' => true]);

    $this->post(tenantHost('acme', "/events/{$event->id}/waitlist"), eventGuest(['party_size' => 1]))
        ->assertRedirect(tenantHost('acme', '/waitlisted'))
        ->assertSessionHas('waitlist');

    $entry = WaitlistEntry::query()->where('event_id', $event->id)->sole();
    expect($entry->position)->toBe(1)
        ->and($entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($entry->party_size)->toBe(1);
});

it('404s the waitlist when the tenant feature is off', function () {
    [$tenant, , $event] = bookEventService(['capacity' => 5, 'booked_count' => 5, 'waitlist_enabled' => true]);
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::Waitlist,
        'enabled' => false,
    ]);

    $this->post(tenantHost('acme', "/events/{$event->id}/waitlist"), eventGuest(['party_size' => 1]))
        ->assertNotFound();

    expect(WaitlistEntry::query()->where('event_id', $event->id)->exists())->toBeFalse();
});

it('rejects a waitlist join on an event that still has room', function () {
    [, , $event] = bookEventService(['capacity' => 10, 'waitlist_enabled' => true]);

    $this->from(tenantHost('acme', '/book'))
        ->post(tenantHost('acme', "/events/{$event->id}/waitlist"), eventGuest(['party_size' => 1]))
        ->assertSessionHasErrors('waitlist');
});

it('404s signing up for another tenant\'s event', function () {
    bookEventService();
    $other = Tenant::factory()->active()->create(['slug' => 'globex']);
    $otherService = Service::factory()->forTenant($other)->eventBased()->create(['active' => true]);
    $otherEvent = Event::factory()->forTenant($other)->at('2026-09-10 18:00:00', '2026-09-10 19:00:00')
        ->create(['service_id' => $otherService->id, 'capacity' => 10]);

    $this->post(tenantHost('acme', "/events/{$otherEvent->id}/book"), eventGuest(['party_size' => 1]))
        ->assertNotFound();
    $this->post(tenantHost('acme', "/events/{$otherEvent->id}/waitlist"), eventGuest(['party_size' => 1]))
        ->assertNotFound();
});

it('redirects the waitlist confirmation to home without a flashed position', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/waitlisted'))
        ->assertRedirect(tenantHost('acme', ''));
});

it('renders the waitlist confirmation with the flashed position', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->withSession(['waitlist' => ['position' => 3, 'service' => 'Jóga', 'starts_local' => '2026-09-10 18:00']])
        ->get(tenantHost('acme', '/waitlisted'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Waitlisted')
            ->where('waitlist.position', 3)
            ->where('waitlist.service', 'Jóga'));
});
