<?php

use App\Enums\BookingMode;
use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Room;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    // Base plan enables waitlist/quote/approval by default; online_payment is off.
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A tenant-admin user in the given tenant (has service.manage). */
function serviceAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);

    return $user;
}

/** Minimal valid payload for a service in the given mode. */
function servicePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test Service',
        'booking_mode' => BookingMode::DurationBased->value,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'price_minor' => 500000,
        'currency' => 'HUF',
        'requires_staff' => true,
        'requires_room' => false,
        'requires_approval' => false,
        'waitlist_enabled' => false,
        'online_payment_required' => false,
        'active' => true,
    ], $overrides);
}

it('lists services, categories and assignable staff/rooms', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    ServiceCategory::factory()->forTenant($tenant)->create(['name' => 'Hair']);
    Service::factory()->forTenant($tenant)->create(['name' => 'Cut']);
    Staff::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Services/Index')
            ->has('services', 1)
            ->has('categories', 1)
            ->has('staff', 1)
            ->where('bookingModes', BookingMode::values()));
});

it('creates a duration_based service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'name' => 'Massage',
            'duration_minutes' => 50,
            'buffer_after_minutes' => 10,
        ]))
        ->assertRedirect();

    $this->assertDatabaseHas('services', [
        'tenant_id' => $tenant->id,
        'name' => 'Massage',
        'booking_mode' => 'duration_based',
        'duration_minutes' => 50,
        'buffer_after_minutes' => 10,
    ]);
});

it('rejects a duration_based service without a duration', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload(['duration_minutes' => null]))
        ->assertSessionHasErrors('duration_minutes');
});

it('creates an event_based service with a capacity and waitlist', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'name' => 'Yoga',
            'booking_mode' => BookingMode::EventBased->value,
            'duration_minutes' => null,
            'capacity' => 12,
            'requires_staff' => false,
            'waitlist_enabled' => true,
        ]))
        ->assertRedirect();

    $this->assertDatabaseHas('services', [
        'name' => 'Yoga',
        'booking_mode' => 'event_based',
        'capacity' => 12,
        'waitlist_enabled' => true,
    ]);
});

it('rejects an event_based service without a capacity', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'booking_mode' => BookingMode::EventBased->value,
            'duration_minutes' => null,
            'capacity' => null,
        ]))
        ->assertSessionHasErrors('capacity');
});

it('creates a no_time_slot service and keeps only its settings', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'name' => 'Recipe',
            'booking_mode' => BookingMode::NoTimeSlot->value,
            'duration_minutes' => null,
            'requires_staff' => false,
            'settings' => ['fulfillment_type' => 'digital', 'deposit_minor' => 999],
        ]))
        ->assertRedirect();

    $service = Service::where('name', 'Recipe')->firstOrFail();
    expect($service->settings)->toBe(['fulfillment_type' => 'digital'])
        ->and($service->duration_minutes)->toBeNull();
});

it('creates a resource_rental service with a deposit in settings', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'name' => 'Court',
            'booking_mode' => BookingMode::ResourceRental->value,
            'duration_minutes' => 90,
            'requires_staff' => false,
            'requires_room' => true,
            'settings' => ['min_duration_minutes' => 30, 'max_duration_minutes' => 120, 'deposit_minor' => 200000],
        ]))
        ->assertRedirect();

    $service = Service::where('name', 'Court')->firstOrFail();
    expect($service->booking_mode)->toBe(BookingMode::ResourceRental)
        ->and($service->settings['deposit_minor'])->toBe(200000)
        ->and($service->duration_minutes)->toBe(90);
});

it('creates a quote_request service when the feature is enabled', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'name' => 'Catering',
            'booking_mode' => BookingMode::QuoteRequest->value,
            'duration_minutes' => null,
            'requires_staff' => false,
            'settings' => ['quote_fields' => ['Head count', 'Menu']],
        ]))
        ->assertRedirect();

    $service = Service::where('name', 'Catering')->firstOrFail();
    expect($service->settings['quote_fields'])->toBe(['Head count', 'Menu']);
});

it('rejects duplicate quote form labels', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    // The labels key the public form's answers (SLO-102): a duplicate would
    // silently overwrite an answer instead of asking twice.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'name' => 'Catering',
            'booking_mode' => BookingMode::QuoteRequest->value,
            'duration_minutes' => null,
            'requires_staff' => false,
            'settings' => ['quote_fields' => ['Head count', 'Head count']],
        ]))
        ->assertSessionHasErrors('settings.quote_fields.1');

    expect(Service::where('name', 'Catering')->exists())->toBeFalse();
});

it('rejects quote_request when the feature is disabled', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::QuoteRequest, 'enabled' => false]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'booking_mode' => BookingMode::QuoteRequest->value,
            'duration_minutes' => null,
            'requires_staff' => false,
        ]))
        ->assertSessionHasErrors('booking_mode');
});

it('rejects online_payment_required when the payment feature is off', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    // feature_online_payment is off by default on the base plan.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload(['online_payment_required' => true]))
        ->assertSessionHasErrors('online_payment_required');
});

it('nulls stale mode fields when the mode does not use them', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    // event_based ignores duration/buffers even if the payload carries them.
    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'name' => 'Workshop',
            'booking_mode' => BookingMode::EventBased->value,
            'duration_minutes' => 90,
            'buffer_before_minutes' => 15,
            'capacity' => 8,
            'requires_staff' => false,
        ]))
        ->assertRedirect();

    $this->assertDatabaseHas('services', [
        'name' => 'Workshop',
        'duration_minutes' => null,
        'buffer_before_minutes' => 0,
    ]);
});

it('syncs staff and room assignments', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $room = Room::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload([
            'name' => 'Assigned',
            'staff_ids' => [$staff->id],
            'room_ids' => [$room->id],
        ]))
        ->assertRedirect();

    $service = Service::where('name', 'Assigned')->firstOrFail();
    expect($service->staff->pluck('id')->all())->toBe([$staff->id])
        ->and($service->rooms->pluck('id')->all())->toBe([$room->id]);
});

it('rejects staff from another tenant in the assignment', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignStaff = Staff::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload(['staff_ids' => [$foreignStaff->id]]))
        ->assertSessionHasErrors('staff_ids.0');
});

it('rejects a room from another tenant in the assignment', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignRoom = Room::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload(['room_ids' => [$foreignRoom->id]]))
        ->assertSessionHasErrors('room_ids.0');
});

it('rejects a category from another tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreignCategory = ServiceCategory::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/services'), servicePayload(['category_id' => $foreignCategory->id]))
        ->assertSessionHasErrors('category_id');
});

it('updates a service and re-syncs assignments', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Old']);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->sync([$staff->id]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/services/{$service->id}"), servicePayload([
            'name' => 'New',
            'staff_ids' => [],
        ]))
        ->assertRedirect();

    $service->refresh();
    expect($service->name)->toBe('New')
        ->and($service->staff()->count())->toBe(0);
});

it('deletes a service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    $service = Service::factory()->forTenant($tenant)->create();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/services/{$service->id}"))
        ->assertRedirect();

    $this->assertDatabaseMissing('services', ['id' => $service->id]);
});

it('404s when updating another tenant\'s service (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = Service::factory()->forTenant($other)->create();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/services/{$foreign->id}"), servicePayload(['name' => 'Hijack']))
        ->assertNotFound();
});

it('creates, updates and deletes a category, leaving services uncategorised', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = serviceAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/service-categories'), ['name' => 'Wellness'])
        ->assertRedirect();
    $category = ServiceCategory::where('name', 'Wellness')->firstOrFail();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/service-categories/{$category->id}"), ['name' => 'Spa'])
        ->assertRedirect();
    $this->assertDatabaseHas('service_categories', ['id' => $category->id, 'name' => 'Spa']);

    $service = Service::factory()->forTenant($tenant)->create(['category_id' => $category->id]);

    $this->actingAs($admin)
        ->delete(tenantHost('acme', "/service-categories/{$category->id}"))
        ->assertRedirect();

    $this->assertDatabaseMissing('service_categories', ['id' => $category->id]);
    $this->assertDatabaseHas('services', ['id' => $service->id, 'category_id' => null]);
});

it('forbids a manager without service.manage (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $manager = User::factory()->create(['tenant_id' => $tenant->id]);
    $manager->assignRole(Role::Manager->value);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/services'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(tenantHost('acme', '/services'))->assertRedirectContains('/login');
});
