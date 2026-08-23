<?php

use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ConversionStatus;
use App\Enums\TenantStatus;
use App\Events\BookingStatusChanged;
use App\Jobs\Analytics\SendMetaConversion;
use App\Models\AnalyticsConversion;
use App\Models\Booking;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Privacy\AnonymizeCustomer;
use App\Services\Privacy\PurgeTenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Meta Conversions API (SLO-173)
|--------------------------------------------------------------------------
|
| This is an outbound call carrying hashed personal data to an ad platform, so
| the tests are weighted towards the calls that must NOT happen: no consent, no
| configuration, not a website booking, not a sale yet. A conversion sent for
| someone who declined cannot be taken back.
|
| The second theme is that it happens exactly once. A booking's status can reach
| `confirmed` more than once in its life, and Meta has no retraction for a
| conversion reported twice.
|
*/

const CAPI_PIXEL = '998877665544332';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    // Nothing in this file may reach the network. A test that silently posted to
    // graph.facebook.com would be a test that reports fake conversions into a
    // real ad account.
    //
    // The stub itself is per-test (`fakeMetaOk()` / `fakeMetaError()`): Http::fake
    // MERGES stubs and the first match wins, so a shared success stub here could
    // not be overridden by the tests that need a failure — they would silently
    // assert against a 200.
    Http::preventStrayRequests();

    // The test queue runs jobs inline, which would send the conversion during
    // the booking request itself and leave nothing for the tests below to drive.
    // Faking it also matches production, where the point of the job is that it
    // does NOT run in the visitor's request.
    Queue::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * @param  array<string, mixed>  $analytics
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function capiTenant(array $analytics = []): array
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'analytics' => $analytics === [] ? [
            'meta_pixel_id' => CAPI_PIXEL,
            'meta_access_token' => 'a-live-looking-token',
        ] : $analytics,
    ]);

    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
        'price_minor' => 1_500_000,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(Carbon::parse('2026-09-07')->isoWeekday(), '09:00', '17:00')
        ->create();

    app(TenantManager::class)->forget();

    return [$tenant, $service, $staff];
}

/** @return array<string, mixed> */
function capiPayload(Service $service, Staff $staff): array
{
    return [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07T07:00:00Z',
        'ends_at' => '2026-09-07T08:00:00Z',
        'name' => 'Teszt Vendég',
        'email' => 'Vendeg@Example.TEST',
        'phone' => '+3611234567',
    ];
}

/**
 * The one row, moved into the state a listener would have claimed it into.
 *
 * The tests below run the job by hand instead of through the queue, so they have
 * to make the same claim the listener makes — a job that finds a row still
 * `pending` is a job nobody dispatched.
 */
function claimedConversion(): AnalyticsConversion
{
    $conversion = AnalyticsConversion::withoutGlobalScopes()->firstOrFail();
    $conversion->forceFill(['status' => ConversionStatus::Queued])->save();

    return $conversion;
}

function fakeMetaOk(): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
}

function fakeMetaError(string $message): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => $message]], 400)]);
}

/** @param  array<string, bool>  $categories */
function capiConsentCookie(array $categories): string
{
    return (string) json_encode([
        'v' => (string) config('consent.version'),
        'c' => $categories,
    ]);
}

// --- Whether a row is created at all ---

it('records a conversion for a booking made by a visitor who allowed marketing', function () {
    [, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->withUnencryptedCookie('_fbp', 'fb.1.1700000000.1234567890')
        ->withUnencryptedCookie('_fbc', 'fb.1.1700000000.AbCdEf')
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff))
        ->assertRedirect();

    $conversion = AnalyticsConversion::withoutGlobalScopes()->first();

    expect($conversion)->not->toBeNull()
        ->and($conversion->event_name)->toBe('Purchase')
        // The browser identifiers, kept only until the event is sent.
        ->and($conversion->fbp)->toBe('fb.1.1700000000.1234567890')
        ->and($conversion->fbc)->toBe('fb.1.1700000000.AbCdEf')
        // The event id IS the booking code — the same value the browser Pixel
        // sends as its eventID, which is how Meta counts one conversion.
        ->and($conversion->event_id)->toBe(Booking::withoutGlobalScopes()->first()?->code);
});

it('records nothing for a visitor who did not allow marketing', function () {
    // The row's absence IS the durable record of "no". There is then nothing for
    // the later stage to find, and no code path that could change its mind hours
    // later when an admin confirms the booking from their own browser.
    [, $service, $staff] = capiTenant();

    $this->post(tenantHost('acme', '/book'), capiPayload($service, $staff))
        ->assertRedirect();

    expect(AnalyticsConversion::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNotPushed(SendMetaConversion::class);
});

it('records nothing when the tenant has a pixel but no access token', function () {
    // Half a configuration is not a working one, and quietly queueing events that
    // can never be sent would fill the table with permanent failures.
    [, $service, $staff] = capiTenant(['meta_pixel_id' => CAPI_PIXEL]);

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff))
        ->assertRedirect();

    expect(AnalyticsConversion::withoutGlobalScopes()->count())->toBe(0);
});

it('records nothing for a booking an admin entered by hand', function () {
    // A phone booking typed in by staff is not a website conversion — and the
    // consent cookie on that request is the ADMIN's answer, not the customer's.
    [$tenant] = capiTenant();
    app(TenantManager::class)->set($tenant);

    Booking::factory()->forTenant($tenant)->create([
        'status' => BookingStatus::Confirmed,
        'source' => BookingSource::Admin,
    ]);

    expect(AnalyticsConversion::withoutGlobalScopes()->count())->toBe(0);
});

// --- When it is sent ---

it('queues the event only once a booking is actually a sale', function () {
    [$tenant, $service, $staff] = capiTenant();

    // A service with approval on would land in `requested`; simulate the same
    // shape directly by starting the booking short of a sale.
    app(TenantManager::class)->set($tenant);
    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'status' => BookingStatus::PendingPayment,
        'source' => BookingSource::Online,
        'price_minor' => 1_500_000,
        'currency' => 'HUF',
    ]);

    AnalyticsConversion::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'booking_id' => $booking->id,
        'provider' => AnalyticsConversion::PROVIDER_META,
        'event_name' => AnalyticsConversion::EVENT_PURCHASE,
        'event_id' => $booking->code,
        'status' => ConversionStatus::Pending,
    ]);

    Queue::assertNotPushed(SendMetaConversion::class);

    $booking->status = BookingStatus::Confirmed;
    $booking->save();
    event(new BookingStatusChanged($booking, BookingStatus::PendingPayment, BookingStatus::Confirmed));

    Queue::assertPushed(SendMetaConversion::class, 1);
});

it('sends the hashed contact details and nothing in the clear', function () {
    fakeMetaOk();
    [$tenant, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff))
        ->assertRedirect();

    $conversion = claimedConversion();
    (new SendMetaConversion($conversion->id))->handle();

    // Lowercased and trimmed before hashing: Meta matches on the hash, so
    // "Vendeg@Example.TEST" and "vendeg@example.test" would be two different
    // people — and a mismatch is not an error anyone sees, just a conversion
    // that quietly fails to attribute.
    $expectedEmail = hash('sha256', 'vendeg@example.test');
    // Digits only, no leading plus.
    $expectedPhone = hash('sha256', '3611234567');

    Http::assertSent(function ($request) use ($expectedEmail, $expectedPhone) {
        $event = $request->data()['data'][0];
        $body = json_encode($request->data());

        return str_contains((string) $request->url(), '/'.CAPI_PIXEL.'/events')
            && $event['event_name'] === 'Purchase'
            && $event['user_data']['em'] === [$expectedEmail]
            && $event['user_data']['ph'] === [$expectedPhone]
            && $event['custom_data']['value'] === 15000.0
            && $event['custom_data']['currency'] === 'HUF'
            // The raw address must appear nowhere in the payload.
            && ! str_contains((string) $body, 'vendeg@example.test');
    });

    expect($conversion->fresh()?->status)->toBe(ConversionStatus::Sent);
});

it('forgets the browser identifiers once the event is sent', function () {
    // They exist to attribute one conversion. Afterwards they are personal data
    // with no remaining purpose, which is the definition of what should not be
    // sitting in a table.
    fakeMetaOk();
    [, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->withUnencryptedCookie('_fbp', 'fb.1.1700000000.1234567890')
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff));

    $conversion = claimedConversion();
    (new SendMetaConversion($conversion->id))->handle();

    expect($conversion->fresh()?->fbp)->toBeNull()
        ->and($conversion->fresh()?->event_source_url)->toBeNull();
});

it('does not send the same conversion twice', function () {
    fakeMetaOk();
    [, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff));

    $conversion = claimedConversion();

    (new SendMetaConversion($conversion->id))->handle();
    // A duplicated job, a replayed status change, a retried queue message: the
    // second run finds the row no longer queued and stops.
    (new SendMetaConversion($conversion->id))->handle();

    Http::assertSentCount(1);
});

it('leaves the booking alone when Meta refuses the event', function () {
    // docs/08's rule: an integration outage never blocks a booking. By this point
    // the booking is already made — Meta being cross about a token can cost a
    // conversion report, never a customer.
    fakeMetaError('Invalid OAuth access token.');

    [, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff))
        ->assertRedirect();

    $conversion = claimedConversion();

    expect(fn () => (new SendMetaConversion($conversion->id))->handle())
        ->toThrow(RuntimeException::class, 'Invalid OAuth access token.');

    // The booking survived the failure untouched.
    expect(Booking::withoutGlobalScopes()->first()?->status)->toBe(BookingStatus::Confirmed);
});

it('gives up cleanly, and says why, when the retries run out', function () {
    [, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff));

    $conversion = claimedConversion();

    (new SendMetaConversion($conversion->id))->failed(new RuntimeException('token expired'));

    $fresh = $conversion->fresh();

    // Recorded on the row, not only in a log: the question a tenant eventually
    // asks is "why did my conversions stop", and the answer has to be attached to
    // the thing that stopped.
    expect($fresh?->status)->toBe(ConversionStatus::Failed)
        ->and($fresh?->last_error)->toContain('token expired');
});

it('drops the event when the tenant removed its configuration in the meantime', function () {
    // A withdrawal, not an error. Retrying into a configuration that no longer
    // exists would burn five attempts to reach the same conclusion.
    [$tenant, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff));

    $conversion = claimedConversion();

    $tenant->analytics = [];
    $tenant->save();

    (new SendMetaConversion($conversion->id))->handle();

    Http::assertNothingSent();
    expect($conversion->fresh()?->status)->toBe(ConversionStatus::Failed);
});

// --- Erasure ---

it('deletes a customer conversion rows when they exercise erasure', function () {
    // Among other things, erasure means "stop reporting me to an advertiser".
    [$tenant, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff));

    expect(AnalyticsConversion::withoutGlobalScopes()->count())->toBe(1);

    // The customer the public flow created for this booking, not one made up
    // here: the erasure matches on the subject the booking actually points at.
    $booking = Booking::withoutGlobalScopes()->firstOrFail();
    $customer = User::query()->findOrFail($booking->customer_id);

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    expect(AnalyticsConversion::withoutGlobalScopes()->count())->toBe(0);
});

it('deletes a tenant conversion rows when the tenant itself is purged', function () {
    [$tenant, $service, $staff] = capiTenant();

    $this->withUnencryptedCookie((string) config('consent.cookie'), capiConsentCookie(['marketing' => true]))
        ->post(tenantHost('acme', '/book'), capiPayload($service, $staff));

    expect(AnalyticsConversion::withoutGlobalScopes()->count())->toBe(1);

    $tenant->status = TenantStatus::Archived;
    $tenant->save();
    $tenant->delete();

    app(PurgeTenant::class)->purge($tenant->fresh(), Carbon::now()->addDay());

    expect(AnalyticsConversion::withoutGlobalScopes()->count())->toBe(0);
});
