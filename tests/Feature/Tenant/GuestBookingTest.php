<?php

use App\Actions\Customer\CreateCustomer;
use App\Enums\BookingMode;
use App\Enums\Feature;
use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\GuestRecipient;
use App\Services\Feature\FeatureResolver;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/**
 * Guest bookings (SLO-128): a visitor whose email already belongs to some other
 * account books WITHOUT logging in and WITHOUT an account being created — the
 * contact details ride on the booking itself.
 *
 * The old behaviour (a validation error on the email field) both blocked the
 * booking and confirmed to an anonymous caller that the address exists on the
 * platform (SLO-106), so the "indistinguishable response" test below is a
 * security regression guard, not a nicety.
 */

// Notifications are faked globally (tests/Pest.php).

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * A bookable duration_based service on `acme`, with a Monday 09:00–17:00 band so
 * 2026-09-07 07:00Z is a real slot (mirrors PublicBookingFlowTest).
 *
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function guestFlowService(): array
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
function guestPayload(Service $service, Staff $staff, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07T07:00:00Z',
        'ends_at' => '2026-09-07T08:00:00Z',
        'name' => 'Teszt Vendég',
        'email' => 'taken@example.test',
        'phone' => '+3611234567',
    ], $overrides);
}

function guestBookings(Tenant $tenant)
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id);
}

it('books as a guest when the email belongs to another tenant, keeping the contact on the row', function () {
    [$tenant, $service, $staff] = guestFlowService();
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    User::factory()->create(['tenant_id' => $other->id, 'email' => 'taken@example.test']);

    $this->post(tenantHost('acme', '/book'), guestPayload($service, $staff))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $booking = guestBookings($tenant)->first();

    expect($booking->customer_id)->toBeNull()
        ->and($booking->guest_name)->toBe('Teszt Vendég')
        ->and($booking->guest_email)->toBe('taken@example.test')
        ->and($booking->guest_phone)->toBe('+3611234567')
        ->and($booking->isGuest())->toBeTrue()
        ->and($booking->contactName())->toBe('Teszt Vendég')
        ->and($booking->contactEmail())->toBe('taken@example.test')
        // The foreign account is untouched: no second row, no role, no membership.
        ->and(User::withoutGlobalScopes()->where('email', 'taken@example.test')->count())->toBe(1)
        ->and(User::withoutGlobalScopes()->where('email', 'taken@example.test')->value('tenant_id'))->toBe($other->id);
});

it('books as a guest when the email belongs to a staff login of the same tenant', function () {
    [$tenant, $service, $staff] = guestFlowService();
    User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'taken@example.test']);

    $this->post(tenantHost('acme', '/book'), guestPayload($service, $staff))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(guestBookings($tenant)->first()->customer_id)->toBeNull();
});

it('books as a guest when the email belongs to the super-admin', function () {
    [$tenant, $service, $staff] = guestFlowService();
    // A tenant-less user is a super-admin by invariant (User::isSuperAdmin).
    User::factory()->create(['tenant_id' => null, 'email' => 'taken@example.test']);

    $this->post(tenantHost('acme', '/book'), guestPayload($service, $staff))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $booking = guestBookings($tenant)->first();

    expect($booking->customer_id)->toBeNull()
        ->and($booking->tenant_id)->toBe($tenant->id);
});

it('mails the confirmation to the guest address and logs it', function () {
    [$tenant, $service, $staff] = guestFlowService();
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    User::factory()->create(['tenant_id' => $other->id, 'email' => 'taken@example.test']);

    $this->post(tenantHost('acme', '/book'), guestPayload($service, $staff))->assertRedirect();

    $booking = guestBookings($tenant)->first();

    Notification::assertSentTo(
        new GuestRecipient('taken@example.test', 'Teszt Vendég'),
        BookingConfirmedNotification::class,
    );

    $log = NotificationLog::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', NotificationType::BookingConfirmed)
        ->where('dedupe_key', 'booking:'.$booking->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->recipient)->toBe('taken@example.test');
});

it('still attaches the booking to an existing customer of this tenant, leaving the guest columns empty', function () {
    [$tenant, $service, $staff] = guestFlowService();

    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $customer = app(CreateCustomer::class)(['name' => 'Régi Ügyfél', 'email' => 'taken@example.test', 'phone' => null]);
    app(TenantManager::class)->forget();

    $this->post(tenantHost('acme', '/book'), guestPayload($service, $staff))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $booking = guestBookings($tenant)->first();

    expect($booking->customer_id)->toBe($customer->getKey())
        ->and($booking->guest_email)->toBeNull()
        ->and($booking->isGuest())->toBeFalse();
});

it('still creates a customer account for an email nobody owns yet', function () {
    [$tenant, $service, $staff] = guestFlowService();

    $this->post(tenantHost('acme', '/book'), guestPayload($service, $staff, ['email' => 'brand-new@example.test']))
        ->assertRedirect();

    $customer = Customer::withoutGlobalScopes()->where('email', 'brand-new@example.test')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->tenant_id)->toBe($tenant->id)
        ->and(guestBookings($tenant)->first()->customer_id)->toBe($customer->getKey())
        ->and(guestBookings($tenant)->first()->guest_email)->toBeNull();
});

it('answers identically whether or not the email already exists (no enumeration oracle)', function () {
    [, $service, $staff] = guestFlowService();
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    User::factory()->create(['tenant_id' => $other->id, 'email' => 'taken@example.test']);

    $taken = $this->post(tenantHost('acme', '/book'), guestPayload($service, $staff, [
        'starts_at' => '2026-09-07T07:00:00Z',
        'ends_at' => '2026-09-07T08:00:00Z',
        'email' => 'taken@example.test',
    ]));
    $unknown = $this->post(tenantHost('acme', '/book'), guestPayload($service, $staff, [
        'starts_at' => '2026-09-07T09:00:00Z',
        'ends_at' => '2026-09-07T10:00:00Z',
        'email' => 'unknown@example.test',
    ]));

    $taken->assertSessionHasNoErrors();
    $unknown->assertSessionHasNoErrors();

    // Same status and same redirect SHAPE (/booked/{code}); only the code differs.
    expect($taken->status())->toBe($unknown->status())
        ->and($taken->headers->get('Location'))->toMatch('#/booked/[A-Z2-9]{8}$#')
        ->and($unknown->headers->get('Location'))->toMatch('#/booked/[A-Z2-9]{8}$#');
});

it('lets a guest order a no_time_slot service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::NoTimeSlot)->create([
        'active' => true,
        'duration_minutes' => null,
        'requires_staff' => false,
        'settings' => ['fulfillment_type' => 'manual'],
    ]);
    User::factory()->create(['tenant_id' => null, 'email' => 'taken@example.test']);

    $this->post(tenantHost('acme', '/order'), [
        'service_id' => $service->id,
        'name' => 'Teszt Vendég',
        'email' => 'taken@example.test',
        'phone' => null,
        'notes' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $booking = guestBookings($tenant)->first();

    expect($booking->customer_id)->toBeNull()
        ->and($booking->guest_email)->toBe('taken@example.test');
});

it('lets a guest sign up for an event', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::EventBased)->create([
        'active' => true,
        'requires_staff' => false,
    ]);
    $event = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'capacity' => 10,
        'booked_count' => 0,
        'starts_at' => Carbon::parse('2026-09-07 08:00:00'),
        'ends_at' => Carbon::parse('2026-09-07 10:00:00'),
    ]);
    User::factory()->create(['tenant_id' => null, 'email' => 'taken@example.test']);

    $this->post(tenantHost('acme', '/events/'.$event->id.'/book'), [
        'service_id' => $service->id,
        'name' => 'Teszt Vendég',
        'email' => 'taken@example.test',
        'party_size' => 2,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $booking = guestBookings($tenant)->first();

    expect($booking->customer_id)->toBeNull()
        ->and($booking->guest_email)->toBe('taken@example.test')
        ->and($booking->party_size)->toBe(2);
});

it('lets a guest send a quote request, filing the message without an author', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::QuoteRequest)->create([
        'active' => true,
        'duration_minutes' => null,
        'requires_staff' => false,
        'settings' => ['quote_fields' => ['Létszám']],
    ]);
    User::factory()->create(['tenant_id' => null, 'email' => 'taken@example.test']);

    $this->post(tenantHost('acme', '/quote'), [
        'service_id' => $service->id,
        'name' => 'Teszt Vendég',
        'email' => 'taken@example.test',
        'phone' => null,
        'notes' => 'Mennyibe kerül?',
        'fields' => ['40 fő'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $quoteRequest = QuoteRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

    expect($quoteRequest)->not->toBeNull()
        ->and($quoteRequest->customer_id)->toBeNull()
        ->and($quoteRequest->guest_email)->toBe('taken@example.test')
        ->and($quoteRequest->isGuest())->toBeTrue();

    $message = QuoteRequestMessage::withoutGlobalScopes()->where('quote_request_id', $quoteRequest->id)->first();

    expect($message)->not->toBeNull()
        ->and($message->user_id)->toBeNull()
        ->and($message->body)->toBe('Mennyibe kerül?');
});

it('still requires an account to join a waitlist (SLO-103 opens that up)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::EventBased)->create([
        'active' => true,
        'requires_staff' => false,
        'waitlist_enabled' => true,
    ]);
    $event = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'capacity' => 1,
        'booked_count' => 1,
        'waitlist_enabled' => true,
        'starts_at' => Carbon::parse('2026-09-07 08:00:00'),
        'ends_at' => Carbon::parse('2026-09-07 10:00:00'),
    ]);
    // The waitlist surface is feature-gated (404 otherwise) — the base plan seed
    // carries the flag, so a failure below is about the account, not the gate.
    expect(app(FeatureResolver::class)->enabled($tenant, Feature::Waitlist))->toBeTrue();

    User::factory()->create(['tenant_id' => null, 'email' => 'taken@example.test']);

    $this->post(tenantHost('acme', '/events/'.$event->id.'/waitlist'), [
        'service_id' => $service->id,
        'name' => 'Teszt Vendég',
        'email' => 'taken@example.test',
        'party_size' => 1,
    ])->assertSessionHasErrors('email');
});
