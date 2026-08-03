<?php

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CreateBooking;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Quote\ChangeQuoteRequestStatus;
use App\Actions\Quote\SubmitQuote;
use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Enums\QuoteRequestStatus;
use App\Enums\TenantStatus;
use App\Events\BookingCanceled;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\BookingCanceledNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingRejectedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Notifications\QuoteReadyNotification;
use App\Notifications\WaitlistOfferNotification;
use App\Services\Booking\WaitlistService;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

// The lifecycle listeners (SLO-109). Notifications are faked globally
// (tests/Pest.php), so these assert routing, the notifications_log claim and the
// idempotency of each dedup key.

afterEach(function () {
    app(TenantManager::class)->forget();
});

/**
 * The tenant under test, bound as the current one — the actions create bookings
 * through the tenant scope, exactly as they do inside a tenant request.
 */
function lifecycleTenant(): Tenant
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'name' => 'Acme Szalon',
        'timezone' => 'Europe/Budapest',
        'locale' => 'hu',
    ]);

    app(TenantManager::class)->set($tenant);

    return $tenant;
}

function lifecycleCustomer(Tenant $tenant): User
{
    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Teszt Elek',
        'email' => 'customer@acme.test',
    ]);
}

function lifecycleService(Tenant $tenant, array $attributes = []): Service
{
    return Service::factory()->forTenant($tenant)->create(array_merge([
        'name' => 'Svédmasszázs',
        'duration_minutes' => 60,
    ], $attributes));
}

function lifecycleBooking(Tenant $tenant, User $customer, Service $service, array $attributes = []): Booking
{
    return Booking::factory()->forTenant($tenant)->create(array_merge([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Confirmed,
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ], $attributes));
}

/** The log rows a tenant accumulated, unfiltered by the tenant scope. */
function lifecycleLogs(NotificationType $type, string $dedupeKey): int
{
    return NotificationLog::withoutGlobalScopes()
        ->where('type', $type)
        ->where('dedupe_key', $dedupeKey)
        ->count();
}

it('emails the customer when their booking is canceled', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $booking = lifecycleBooking($tenant, $customer, lifecycleService($tenant));

    app(CancelBooking::class)($booking, null, 'A munkatárs megbetegedett');

    Notification::assertSentTo($customer, BookingCanceledNotification::class);

    $log = NotificationLog::withoutGlobalScopes()
        ->where('type', NotificationType::BookingCanceled)
        ->where('dedupe_key', 'booking:'.$booking->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->tenant_id)->toBe($tenant->id)
        ->and($log->recipient)->toBe('customer@acme.test');
});

it('does not resend the cancellation when the event fires twice', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $booking = lifecycleBooking($tenant, $customer, lifecycleService($tenant));

    app(CancelBooking::class)($booking);
    event(new BookingCanceled($booking->fresh()));

    Notification::assertSentTimes(BookingCanceledNotification::class, 1);
    expect(lifecycleLogs(NotificationType::BookingCanceled, 'booking:'.$booking->id))->toBe(1);
});

it('emails the customer the reason when their booking request is rejected', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $booking = lifecycleBooking($tenant, $customer, lifecycleService($tenant), [
        'status' => BookingStatus::Requested,
    ]);

    app(ChangeBookingStatus::class)($booking, BookingStatus::Rejected, null, 'Erre a napra betelt a naptárunk');

    Notification::assertSentTo($customer, BookingRejectedNotification::class);
    expect(lifecycleLogs(NotificationType::BookingRejected, 'booking:'.$booking->id))->toBe(1);

    $mail = (new BookingRejectedNotification($booking->fresh(), $tenant))->toMail($customer);
    $lines = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));

    expect($mail->subject)->toContain('Acme Szalon')
        ->and($lines)->toContain('Erre a napra betelt a naptárunk');
});

it('sends one modification email for a reschedule — no cancellation, no confirmation', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant);
    $original = lifecycleBooking($tenant, $customer, $service);

    // The original's own confirmation already went out on create; start clean so
    // this asserts only what the reschedule itself sends.
    Notification::fake();

    $new = app(RescheduleBooking::class)($original, $service, [
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-02 08:00:00',
        'ends_at' => '2026-09-02 09:00:00',
        'source' => 'admin',
    ]);

    // Exactly one mail: "your booking was moved" — not a cancellation of the old
    // one plus a confirmation of the new one.
    Notification::assertSentTimes(BookingRescheduledNotification::class, 1);
    Notification::assertNotSentTo($customer, BookingCanceledNotification::class);
    Notification::assertNotSentTo($customer, BookingConfirmedNotification::class);

    expect($new->rescheduled_from_id)->toBe($original->id)
        ->and(lifecycleLogs(NotificationType::BookingModified, 'booking:'.$new->id))->toBe(1)
        ->and(lifecycleLogs(NotificationType::BookingCanceled, 'booking:'.$original->id))->toBe(0);
});

it('mails nothing and claims nothing when the reschedule rolls back', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $original = lifecycleBooking($tenant, $customer, $service, ['staff_id' => $staff->id]);

    // Someone else already holds the slot we are moving into.
    $rival = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'rival@acme.test']);
    lifecycleBooking($tenant, $rival, $service, [
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-02 08:00:00',
        'ends_at' => '2026-09-02 09:00:00',
    ]);

    Notification::fake();

    expect(fn () => app(RescheduleBooking::class)($original, $service, [
        'customer_id' => $customer->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-02 08:00:00',
        'ends_at' => '2026-09-02 09:00:00',
        'source' => 'admin',
    ]))->toThrow(SlotUnavailableException::class);

    // The whole transaction rolled back: the booking still stands, so the customer
    // must hear nothing — and no claim row may survive to block a later real send.
    expect($original->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(lifecycleLogs(NotificationType::BookingCanceled, 'booking:'.$original->id))->toBe(0);
    Notification::assertNothingSent();
});

it('renders the modification mail with the old and the new time and the new code', function () {
    config(['app.url' => 'https://slot4u.test']);
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant);
    $original = lifecycleBooking($tenant, $customer, $service);

    $new = app(RescheduleBooking::class)($original, $service, [
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-02 08:00:00',
        'ends_at' => '2026-09-02 09:00:00',
        'source' => 'admin',
    ]);

    $mail = (new BookingRescheduledNotification($new->fresh(), $original->fresh(), $tenant))->toMail($customer);
    $lines = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));

    // Stored UTC, shown in the tenant's timezone (08:00 UTC → 10:00 Europe/Budapest).
    expect($lines)->toContain('2026-09-01 10:00')
        ->and($lines)->toContain('2026-09-02 10:00')
        ->and($lines)->toContain($new->code)
        ->and($mail->actionUrl)->toBe('https://acme.'.config('tenancy.central_domain').'/booked/'.$new->code);
});

it('still calls it a modification when the rescheduled booking needs approval first', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant, ['requires_approval' => true]);
    $original = lifecycleBooking($tenant, $customer, $service);

    Notification::fake();

    // source=online → the approval gate applies: the replacement starts `requested`.
    $new = app(RescheduleBooking::class)($original, $service, [
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-02 08:00:00',
        'ends_at' => '2026-09-02 09:00:00',
        'source' => 'online',
    ]);

    expect($new->status)->toBe(BookingStatus::Requested);
    Notification::assertNothingSent();

    // Approval lands in a later request — the persisted predecessor is what keeps
    // this a modification rather than a plain confirmation.
    $approved = app(ChangeBookingStatus::class)($new, BookingStatus::Approved);
    app(ChangeBookingStatus::class)($approved, BookingStatus::Confirmed);

    Notification::assertSentTimes(BookingRescheduledNotification::class, 1);
    Notification::assertNotSentTo($customer, BookingConfirmedNotification::class);
    expect(lifecycleLogs(NotificationType::BookingModified, 'booking:'.$new->id))->toBe(1);
});

it('emails the waitlisted customer when a seat is offered to them', function () {
    config(['app.url' => 'https://slot4u.test']);
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant);
    $event = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'starts_at' => '2026-09-05 16:00:00',
        'ends_at' => '2026-09-05 18:00:00',
        'capacity' => 10,
        'booked_count' => 9,
        'waitlist_enabled' => true,
    ]);
    $entry = WaitlistEntry::factory()->forEvent($event)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'party_size' => 1,
    ]);

    $offered = app(WaitlistService::class)->offerNext($event->id, $tenant->id);

    expect($offered?->id)->toBe($entry->id);
    Notification::assertSentTo($customer, WaitlistOfferNotification::class);
    expect(lifecycleLogs(NotificationType::WaitlistOffer, 'waitlist_entry:'.$entry->id))->toBe(1);

    $mail = (new WaitlistOfferNotification($entry->fresh(), $tenant))->toMail($customer);
    $lines = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));

    // Stored UTC, shown in the tenant's timezone (16:00 UTC → 18:00 Europe/Budapest).
    expect($lines)->toContain('2026-09-05 18:00')
        ->and($lines)->toContain('Svédmasszázs')
        ->and($mail->actionUrl)->toBe('https://acme.'.config('tenancy.central_domain').'/book?service='.$service->id);
});

it('does not re-offer by email when the entry is already offered', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant);
    $event = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'capacity' => 10,
        'booked_count' => 9,
        'waitlist_enabled' => true,
    ]);
    $entry = WaitlistEntry::factory()->forEvent($event)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
    ]);

    $waitlist = app(WaitlistService::class);
    $waitlist->offerNext($event->id, $tenant->id);
    // A second run finds an active offer and no-ops; even if it re-fired, the
    // dedup key holds.
    $waitlist->offerNext($event->id, $tenant->id);

    Notification::assertSentTimes(WaitlistOfferNotification::class, 1);
    expect(lifecycleLogs(NotificationType::WaitlistOffer, 'waitlist_entry:'.$entry->id))->toBe(1);
});

it('emails the customer when their quote request is priced', function () {
    config(['app.url' => 'https://slot4u.test']);
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant, ['name' => 'Céges rendezvény']);
    $quoteRequest = QuoteRequest::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'status' => QuoteRequestStatus::InProgress,
    ]);

    app(SubmitQuote::class)($quoteRequest, 1_200_000, 'HUF', Carbon::parse('2026-10-01 09:00:00'));

    Notification::assertSentTo($customer, QuoteReadyNotification::class);
    expect(lifecycleLogs(NotificationType::QuoteReady, 'quote_request:'.$quoteRequest->id))->toBe(1);

    $mail = (new QuoteReadyNotification($quoteRequest->fresh(), $tenant))->toMail($customer);
    $lines = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));

    expect($lines)->toContain('Céges rendezvény')
        ->and($mail->actionUrl)->toBe('https://acme.'.config('tenancy.central_domain').'/my/quotes');
});

it('does not email the quote until it is actually priced', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $quoteRequest = QuoteRequest::factory()->forTenant($tenant)->create([
        'service_id' => lifecycleService($tenant)->id,
        'customer_id' => $customer->id,
        'status' => QuoteRequestStatus::New,
    ]);

    // new → in_progress is internal progress, not an offer.
    app(ChangeQuoteRequestStatus::class)($quoteRequest, QuoteRequestStatus::InProgress);

    Notification::assertNothingSent();
    expect(NotificationLog::withoutGlobalScopes()->count())->toBe(0);
});

it('never notifies a booking that has no customer to notify', function () {
    $tenant = lifecycleTenant();
    // An admin walk-in booking may carry no customer record at all.
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => null,
        'service_id' => lifecycleService($tenant)->id,
        'status' => BookingStatus::Confirmed,
    ]);

    app(CancelBooking::class)($booking);

    Notification::assertNothingSent();
    expect(NotificationLog::withoutGlobalScopes()->count())->toBe(0);
});

it('scopes a lifecycle log row to the booking tenant', function () {
    $tenant = lifecycleTenant();
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $customer = lifecycleCustomer($tenant);
    $booking = lifecycleBooking($tenant, $customer, lifecycleService($tenant));

    app(CancelBooking::class)($booking);

    // Bound to another tenant, the global scope hides every row of this one.
    app(TenantManager::class)->set($other);
    expect(NotificationLog::query()->count())->toBe(0);
});

it('claims the log row for the booking tenant even when another tenant is bound', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $booking = lifecycleBooking($tenant, $customer, lifecycleService($tenant));

    // The ambient tenant is NOT the booking's. The claim must still be filed under
    // the booking's tenant — it is the key the dedup lookup uses, so a row written
    // under the bound tenant would be missed next time and mail twice.
    app(TenantManager::class)->set(Tenant::factory()->active()->create(['slug' => 'other']));

    app(CancelBooking::class)($booking);

    $log = NotificationLog::withoutGlobalScopes()
        ->where('type', NotificationType::BookingCanceled)
        ->where('dedupe_key', 'booking:'.$booking->id)
        ->firstOrFail();

    expect($log->tenant_id)->toBe($tenant->id);
});

it('keeps the tenant-less expiry command alive when a tenant has been archived', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $booking = lifecycleBooking($tenant, $customer, lifecycleService($tenant), [
        'status' => BookingStatus::Requested,
        'hold_expires_at' => Carbon::now()->subHour(),
    ]);

    // Archiving soft-deletes the tenant; its bookings stay behind.
    $tenant->delete();

    // The scheduled command runs with NO bound tenant, across every tenant's rows.
    // An archived tenant's booking must not take the command (and with it every
    // other tenant's expiry) down — the plain `tenant` relation is null for it.
    app(TenantManager::class)->forget();

    $this->artisan('bookings:expire-soft-holds')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled);
    Notification::assertNothingSent();
    expect(NotificationLog::withoutGlobalScopes()->count())->toBe(0);
});

it('does not mail on behalf of a suspended tenant', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $booking = lifecycleBooking($tenant, $customer, lifecycleService($tenant));

    Notification::fake();

    // A suspended tenant's public pages answer 503 (EnsureTenantActive), so every
    // link in the mail would be dead.
    $tenant->update(['status' => TenantStatus::Suspended]);

    app(CancelBooking::class)($booking);

    Notification::assertNothingSent();
    expect(lifecycleLogs(NotificationType::BookingCanceled, 'booking:'.$booking->id))->toBe(0);
});

it('refuses to chain a replacement onto another tenant’s booking', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant);

    // Bind the other tenant while building its data — BelongsToTenant stamps the
    // BOUND tenant onto anything created, so a booking made under `acme` would not
    // be foreign at all.
    $foreign = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($foreign);
    $foreignBooking = Booking::factory()->forTenant($foreign)->create([
        'service_id' => lifecycleService($foreign, ['name' => 'Idegen szolgáltatás'])->id,
    ]);
    expect($foreignBooking->tenant_id)->toBe($foreign->id);

    app(TenantManager::class)->set($tenant);

    expect(fn () => app(CreateBooking::class)(
        $service,
        [
            'customer_id' => $customer->id,
            'starts_at' => '2026-09-02 08:00:00',
            'ends_at' => '2026-09-02 09:00:00',
            'source' => 'admin',
        ],
        null,
        $foreignBooking,
    ))->toThrow(RuntimeException::class);
});

it('sends nothing when the admin moves a booking with the notification switched off', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant);
    $original = lifecycleBooking($tenant, $customer, $service);

    Notification::fake();

    $new = app(RescheduleBooking::class)($original, $service, [
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-02 08:00:00',
        'ends_at' => '2026-09-02 09:00:00',
        'source' => 'admin',
    ], null, false, notifyCustomer: false);

    // The move itself happened — only the mail was suppressed (docs/04 §2, SLO-44).
    Notification::assertNothingSent();

    expect($new->rescheduled_from_id)->toBe($original->id)
        ->and($new->status)->toBe(BookingStatus::Confirmed)
        ->and($original->fresh()->status)->toBe(BookingStatus::Canceled)
        // No claim row either: a suppressed mail must not consume the dedup key and
        // block a genuine send later on.
        ->and(lifecycleLogs(NotificationType::BookingModified, 'booking:'.$new->id))->toBe(0);
});

it('keeps notifying every other booking after one silent move', function () {
    $tenant = lifecycleTenant();
    $customer = lifecycleCustomer($tenant);
    $service = lifecycleService($tenant);
    $silent = lifecycleBooking($tenant, $customer, $service);
    $loud = lifecycleBooking($tenant, $customer, $service, [
        'starts_at' => '2026-09-05 08:00:00',
        'ends_at' => '2026-09-05 09:00:00',
    ]);

    Notification::fake();

    app(RescheduleBooking::class)($silent, $service, [
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-02 08:00:00',
        'ends_at' => '2026-09-02 09:00:00',
        'source' => 'admin',
    ], null, false, notifyCustomer: false);

    // The flag is per call, not sticky on the action (which is container-resolved
    // and may be reused within the request).
    app(RescheduleBooking::class)($loud, $service, [
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-06 08:00:00',
        'ends_at' => '2026-09-06 09:00:00',
        'source' => 'admin',
    ]);

    Notification::assertSentTimes(BookingRescheduledNotification::class, 1);
});
