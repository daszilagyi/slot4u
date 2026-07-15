<?php

use App\Actions\Booking\CreateBooking;
use App\Actions\Quote\AcceptQuoteRequest;
use App\Actions\Quote\ChangeQuoteRequestStatus;
use App\Actions\Quote\CreateQuoteRequest;
use App\Actions\Quote\PostQuoteMessage;
use App\Actions\Quote\RejectQuoteRequest;
use App\Actions\Quote\SubmitQuote;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\QuoteRequestStatus;
use App\Enums\Role;
use App\Exceptions\InvalidQuoteTransitionException;
use App\Models\Booking;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function qrTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);

    return $tenant;
}

function qrService(Tenant $tenant, array $overrides = []): Service
{
    return Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::QuoteRequest,
        'duration_minutes' => null,
        'requires_staff' => false,
        'requires_approval' => false,
        'online_payment_required' => false,
        'price_minor' => 100000,
        'currency' => 'HUF',
        'settings' => ['quote_fields' => ['guests', 'date']],
    ], $overrides));
}

function qrAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

function qrCustomer(Tenant $tenant): User
{
    return User::factory()->create(['tenant_id' => $tenant->id]);
}

// --- Creation ---

it('creates a quote request in the new state with the filled form', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $customer = qrCustomer($tenant);

    $quote = app(CreateQuoteRequest::class)($service, [
        'customer_id' => $customer->id,
        'parameters' => ['guests' => '120', 'date' => '2026-10-10'],
    ]);

    expect($quote->status)->toBe(QuoteRequestStatus::New)
        ->and($quote->tenant_id)->toBe($tenant->id)
        ->and($quote->parameters)->toBe(['guests' => '120', 'date' => '2026-10-10'])
        ->and($quote->price_minor)->toBeNull();
});

it('refuses to create a quote request for a non quote_request service', function () {
    $tenant = qrTenant();
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60,
    ]);

    expect(fn () => app(CreateQuoteRequest::class)($service, []))
        ->toThrow(RuntimeException::class);
});

// --- Lifecycle transitions ---

it('walks new → in_progress → quoted via SubmitQuote', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $quote = app(CreateQuoteRequest::class)($service, []);

    app(SubmitQuote::class)($quote, 250000, 'HUF', Carbon::parse('2026-12-31 23:59:59'), 'Belső jegyzet.', qrAdmin($tenant));

    $quote->refresh();
    expect($quote->status)->toBe(QuoteRequestStatus::Quoted)
        ->and($quote->price_minor)->toBe(250000)
        ->and($quote->currency)->toBe('HUF')
        ->and($quote->valid_until->toDateTimeString())->toBe('2026-12-31 23:59:59')
        ->and($quote->internal_notes)->toBe('Belső jegyzet.');
});

it('submits a quote from an already in_progress request', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $quote = app(CreateQuoteRequest::class)($service, []);
    app(ChangeQuoteRequestStatus::class)($quote, QuoteRequestStatus::InProgress, qrAdmin($tenant));

    app(SubmitQuote::class)($quote, 300000, 'HUF', null, null, qrAdmin($tenant));

    expect($quote->fresh()->status)->toBe(QuoteRequestStatus::Quoted)
        ->and($quote->fresh()->price_minor)->toBe(300000);
});

it('rejects a quote request from any active state', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $admin = qrAdmin($tenant);

    // From new.
    $fromNew = app(CreateQuoteRequest::class)($service, []);
    app(RejectQuoteRequest::class)($fromNew, $admin);
    expect($fromNew->fresh()->status)->toBe(QuoteRequestStatus::Rejected);

    // From in_progress.
    $fromInProgress = app(CreateQuoteRequest::class)($service, []);
    app(ChangeQuoteRequestStatus::class)($fromInProgress, QuoteRequestStatus::InProgress, $admin);
    app(RejectQuoteRequest::class)($fromInProgress, $admin);
    expect($fromInProgress->fresh()->status)->toBe(QuoteRequestStatus::Rejected);

    // From quoted.
    $fromQuoted = QuoteRequest::factory()->forTenant($tenant)->quoted()->create(['service_id' => $service->id]);
    app(RejectQuoteRequest::class)($fromQuoted, $admin);
    expect($fromQuoted->fresh()->status)->toBe(QuoteRequestStatus::Rejected);
});

it('refuses an illegal transition (accepting a request that was never quoted)', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $quote = app(CreateQuoteRequest::class)($service, []);

    expect(fn () => app(AcceptQuoteRequest::class)($quote, qrAdmin($tenant)))
        ->toThrow(InvalidQuoteTransitionException::class);

    expect($quote->fresh()->status)->toBe(QuoteRequestStatus::New);
});

it('refuses to change a terminal (rejected) request', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $quote = QuoteRequest::factory()->forTenant($tenant)->status(QuoteRequestStatus::Rejected)->create([
        'service_id' => $service->id,
    ]);

    expect(fn () => app(ChangeQuoteRequestStatus::class)($quote, QuoteRequestStatus::Accepted, qrAdmin($tenant)))
        ->toThrow(InvalidQuoteTransitionException::class);
});

// --- Accept → booking generation (AC) ---

it('accepts a quoted request and generates a booking with the quote price', function () {
    $tenant = qrTenant();
    $service = qrService($tenant, ['price_minor' => 100000]); // list price differs from the quote
    $customer = qrCustomer($tenant);
    $quote = QuoteRequest::factory()->forTenant($tenant)->quoted(275000, 'HUF')->create([
        'service_id' => $service->id, 'customer_id' => $customer->id,
    ]);

    $result = app(AcceptQuoteRequest::class)($quote, qrAdmin($tenant));

    $result->refresh();
    expect($result->status)->toBe(QuoteRequestStatus::Accepted)
        ->and($result->booking_id)->not->toBeNull();

    $booking = $result->booking;
    expect($booking->booking_mode)->toBe(BookingMode::QuoteRequest)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->price_minor)->toBe(275000) // the accepted quote price, not the 100000 list price
        ->and($booking->currency)->toBe('HUF')
        ->and($booking->customer_id)->toBe($customer->id)
        ->and($booking->source)->toBe(BookingSource::Admin)
        ->and($booking->starts_at)->toBeNull();
});

it('refuses a concurrent accept — no orphan booking is generated', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $quote = QuoteRequest::factory()->forTenant($tenant)->quoted(200000, 'HUF')->create(['service_id' => $service->id]);

    // Simulate a concurrent accept that already committed underneath this one.
    QuoteRequest::withoutGlobalScopes()->whereKey($quote->id)->update(['status' => QuoteRequestStatus::Accepted->value]);

    // The stale in-memory request still thinks it is quoted; the lock re-check
    // catches the real state and refuses to clobber it — rolling back the booking
    // its transaction had already generated.
    expect(fn () => app(AcceptQuoteRequest::class)($quote, qrAdmin($tenant)))
        ->toThrow(InvalidQuoteTransitionException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('accepts a quoted request without generating a booking when asked not to', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $quote = QuoteRequest::factory()->forTenant($tenant)->quoted()->create(['service_id' => $service->id]);

    app(AcceptQuoteRequest::class)($quote, qrAdmin($tenant), generateBooking: false);

    expect($quote->fresh()->status)->toBe(QuoteRequestStatus::Accepted)
        ->and($quote->fresh()->booking_id)->toBeNull();
});

it('runs the full lifecycle: request → quote → accept → booking', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $customer = qrCustomer($tenant);
    $admin = qrAdmin($tenant);

    $quote = app(CreateQuoteRequest::class)($service, [
        'customer_id' => $customer->id,
        'parameters' => ['guests' => '200'],
    ]);
    expect($quote->status)->toBe(QuoteRequestStatus::New);

    app(SubmitQuote::class)($quote, 480000, 'HUF', null, null, $admin);
    expect($quote->fresh()->status)->toBe(QuoteRequestStatus::Quoted);

    app(AcceptQuoteRequest::class)($quote, $admin);

    $quote->refresh();
    expect($quote->status)->toBe(QuoteRequestStatus::Accepted)
        ->and($quote->booking->price_minor)->toBe(480000);
});

// --- The quote flow can't be bypassed via the generic booking path ---

it('refuses to create a quote_request booking directly through CreateBooking', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);

    // Without the accept-path authorisation flag, CreateBooking must refuse the mode.
    expect(fn () => app(CreateBooking::class)($service, ['source' => 'admin']))
        ->toThrow(RuntimeException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('rejects a quote_request service on the generic /bookings endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = qrService($tenant);
    $admin = qrAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/bookings'), ['service_id' => $service->id, 'party_size' => 1])
        ->assertSessionHasErrors('service_id');

    expect(Booking::query()->count())->toBe(0);
});

// --- valid_until timezone conversion (docs/01 §7) ---

it('stores valid_until converted from tenant timezone to UTC', function () {
    // Freeze the clock to a fixed summer instant before the submitted date, so the
    // "must be after now" validation is deterministic — the hardcoded date used to
    // be a time bomb that failed once wall-clock passed 2026-07-15 12:00 CEST
    // (SLO-115). Reset before the assertions so a failure can't leak the fake time.
    Carbon::setTestNow('2026-06-15 09:00:00');

    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    app(TenantManager::class)->set($tenant);
    $service = qrService($tenant);
    $quote = app(CreateQuoteRequest::class)($service, []);
    $admin = qrAdmin($tenant);
    app(TenantManager::class)->forget();

    // 12:00 Budapest summer time (CEST, UTC+2) → 10:00 UTC.
    $response = $this->actingAs($admin)
        ->post(tenantHost('acme', "/quote-requests/{$quote->id}/submit"), [
            'price_minor' => 400000, 'currency' => 'HUF', 'valid_until' => '2026-07-15T12:00',
        ]);

    Carbon::setTestNow();

    $response->assertRedirect()->assertSessionHasNoErrors();

    expect($quote->fresh()->valid_until->toDateTimeString())->toBe('2026-07-15 10:00:00');
});

// --- Messages ---

it('appends a message to a quote request thread', function () {
    $tenant = qrTenant();
    $service = qrService($tenant);
    $admin = qrAdmin($tenant);
    $quote = app(CreateQuoteRequest::class)($service, []);

    $message = app(PostQuoteMessage::class)($quote, 'Küldjük az ajánlatot holnap.', $admin);

    expect($message->body)->toBe('Küldjük az ajánlatot holnap.')
        ->and($message->user_id)->toBe($admin->id)
        ->and($message->tenant_id)->toBe($tenant->id)
        ->and($quote->messages()->count())->toBe(1);
});

// --- Tenant isolation ---

it('scopes quote requests to the current tenant', function () {
    $tenantA = qrTenant(['slug' => 'acme']);
    $serviceA = qrService($tenantA);
    app(CreateQuoteRequest::class)($serviceA, []);

    $tenantB = qrTenant(['slug' => 'other']);
    $serviceB = qrService($tenantB);
    app(CreateQuoteRequest::class)($serviceB, []);

    // Ambient tenant is B now — only B's request is visible.
    expect(QuoteRequest::query()->count())->toBe(1)
        ->and(QuoteRequest::query()->first()->tenant_id)->toBe($tenantB->id);
});

it('scopes quote request messages to the current tenant', function () {
    $tenantA = qrTenant(['slug' => 'acme']);
    $quoteA = app(CreateQuoteRequest::class)(qrService($tenantA), []);
    app(PostQuoteMessage::class)($quoteA, 'A üzenet');

    $tenantB = qrTenant(['slug' => 'other']);
    $quoteB = app(CreateQuoteRequest::class)(qrService($tenantB), []);
    app(PostQuoteMessage::class)($quoteB, 'B üzenet');

    // Ambient tenant is B now — only B's message is visible under the scope.
    expect(QuoteRequestMessage::query()->count())->toBe(1)
        ->and(QuoteRequestMessage::query()->first()->tenant_id)->toBe($tenantB->id)
        ->and(QuoteRequestMessage::query()->first()->body)->toBe('B üzenet');
});

// --- HTTP ---

it('lets an admin create a quote request via the endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = qrService($tenant);
    $admin = qrAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/quote-requests'), [
            'service_id' => $service->id,
            'parameters' => ['guests' => '80'],
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(QuoteRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('rejects creating a quote request for a non quote_request service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60,
    ]);
    $admin = qrAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/quote-requests'), ['service_id' => $service->id])
        ->assertSessionHasErrors('service_id');
});

it('blocks the quote endpoint when feature_quote_request is disabled (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::QuoteRequest, 'enabled' => false]);
    $service = qrService($tenant);
    $admin = qrAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/quote-requests'), ['service_id' => $service->id])
        ->assertForbidden();
});

it('lets an admin submit, then accept, a quote via the endpoints', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = qrService($tenant);
    $quote = app(CreateQuoteRequest::class)($service, []);
    $admin = qrAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/quote-requests/{$quote->id}/submit"), [
            'price_minor' => 350000, 'currency' => 'HUF',
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($quote->fresh()->status)->toBe(QuoteRequestStatus::Quoted);

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/quote-requests/{$quote->id}/accept"))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($quote->fresh()->status)->toBe(QuoteRequestStatus::Accepted)
        ->and($quote->fresh()->booking->price_minor)->toBe(350000);
});

it('returns a 422 when submitting fails the state machine', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = qrService($tenant);
    $quote = QuoteRequest::factory()->forTenant($tenant)->status(QuoteRequestStatus::Rejected)->create(['service_id' => $service->id]);
    $admin = qrAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/quote-requests/{$quote->id}/submit"), [
            'price_minor' => 350000, 'currency' => 'HUF',
        ])
        ->assertSessionHasErrors('quote_request');
});

it('lets an admin post a message via the endpoint', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = qrService($tenant);
    $quote = app(CreateQuoteRequest::class)($service, []);
    $admin = qrAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/quote-requests/{$quote->id}/messages"), ['body' => 'Szia!'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(QuoteRequestMessage::withoutGlobalScopes()->where('quote_request_id', $quote->id)->count())->toBe(1);
});

it('forbids the lifecycle actions without booking.edit (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    app(TenantManager::class)->set($tenant);
    $service = qrService($tenant);
    $quote = app(CreateQuoteRequest::class)($service, []);
    // A user with booking.create (via a role that lacks booking.edit) — actually
    // use a bare user with no roles: no booking permissions at all.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $this->actingAs($user)
        ->post(tenantHost('acme', "/quote-requests/{$quote->id}/accept"))
        ->assertForbidden();
});

it('404s when acting on another tenant\'s quote request (cross-tenant)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $admin = qrAdmin($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    $service = qrService($other);
    $foreign = app(CreateQuoteRequest::class)($service, []);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/quote-requests/{$foreign->id}/reject"))
        ->assertNotFound();
});
