<?php

use App\Enums\Feature;
use App\Models\Event;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use Database\Seeders\BasePlanSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    // Seeds plan_features → feature_waitlist is on for the base plan by default.
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * An event_based service in an active tenant, plus its announcing staff.
 *
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function publicEventService(): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->eventBased()->create(['active' => true]);
    $staff = Staff::factory()->forTenant($tenant)->create(['name' => 'Anna']);

    return [$tenant, $service, $staff];
}

it('lists upcoming scheduled events with capacity and waitlist state', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    [$tenant, $service, $staff] = publicEventService();

    $open = Event::factory()->forTenant($tenant)->at('2026-09-10 18:00:00', '2026-09-10 19:00:00')
        ->create(['service_id' => $service->id, 'staff_id' => $staff->id, 'capacity' => 10]);
    $full = Event::factory()->forTenant($tenant)->at('2026-09-11 18:00:00', '2026-09-11 19:00:00')->withBookings(5)
        ->create(['service_id' => $service->id, 'capacity' => 5, 'waitlist_enabled' => false]);
    $waitlisted = Event::factory()->forTenant($tenant)->at('2026-09-12 18:00:00', '2026-09-12 19:00:00')->withBookings(5)
        ->create(['service_id' => $service->id, 'capacity' => 5, 'waitlist_enabled' => true]);

    $this->get(tenantHost('acme', "/book?service={$service->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Book')
            ->where('service.booking_mode', 'event_based')
            ->has('events', 3)
            // Ordered by start; the open event first.
            ->where('events.0.id', $open->id)
            ->where('events.0.remaining', 10)
            ->where('events.0.is_full', false)
            ->where('events.0.staff', 'Anna')
            ->where('events.0.waitlist_available', false)
            // Full, waitlist disabled on the event.
            ->where('events.1.id', $full->id)
            ->where('events.1.is_full', true)
            ->where('events.1.remaining', 0)
            ->where('events.1.waitlist_available', false)
            // Full + waitlist enabled + feature on (base plan default).
            ->where('events.2.id', $waitlisted->id)
            ->where('events.2.is_full', true)
            ->where('events.2.waitlist_available', true));
});

it('excludes past and canceled events', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    [$tenant, $service] = publicEventService();

    $future = Event::factory()->forTenant($tenant)->at('2026-09-10 18:00:00', '2026-09-10 19:00:00')
        ->create(['service_id' => $service->id, 'capacity' => 10]);
    Event::factory()->forTenant($tenant)->at('2026-08-01 18:00:00', '2026-08-01 19:00:00')
        ->create(['service_id' => $service->id, 'capacity' => 10]);
    Event::factory()->forTenant($tenant)->canceled()->at('2026-09-20 18:00:00', '2026-09-20 19:00:00')
        ->create(['service_id' => $service->id, 'capacity' => 10]);

    $this->get(tenantHost('acme', "/book?service={$service->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 1)
            ->where('events.0.id', $future->id));
});

it('does not list another tenant\'s events', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    [$tenant, $service] = publicEventService();
    $mine = Event::factory()->forTenant($tenant)->at('2026-09-10 18:00:00', '2026-09-10 19:00:00')
        ->create(['service_id' => $service->id, 'capacity' => 10]);

    // A different tenant with its own event_based service + scheduled event.
    $other = Tenant::factory()->active()->create(['slug' => 'globex']);
    $otherService = Service::factory()->forTenant($other)->eventBased()->create(['active' => true]);
    Event::factory()->forTenant($other)->at('2026-09-11 18:00:00', '2026-09-11 19:00:00')
        ->create(['service_id' => $otherService->id, 'capacity' => 10]);

    $this->get(tenantHost('acme', "/book?service={$service->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 1)
            ->where('events.0.id', $mine->id));
});

it('hides the waitlist option when the tenant feature is off', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    [$tenant, $service] = publicEventService();
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::Waitlist,
        'enabled' => false,
    ]);

    Event::factory()->forTenant($tenant)->at('2026-09-12 18:00:00', '2026-09-12 19:00:00')->withBookings(5)
        ->create(['service_id' => $service->id, 'capacity' => 5, 'waitlist_enabled' => true]);

    $this->get(tenantHost('acme', "/book?service={$service->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.0.is_full', true)
            ->where('events.0.waitlist_available', false));
});
