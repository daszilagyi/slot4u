<?php

use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Enums\TenantStatus;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\BookingReminderNotification;
use App\Notifications\WaitlistOfferNotification;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// The scheduled notification jobs (SLO-110). They run tenant-less, exactly as the
// scheduler runs them: no tenant is bound anywhere in this file.

afterEach(function () {
    Carbon::setTestNow();
    app(TenantManager::class)->forget();
});

function reminderTenant(array $attributes = []): Tenant
{
    return Tenant::factory()->active()->create(array_merge([
        'slug' => 'acme',
        'name' => 'Acme Szalon',
        'timezone' => 'Europe/Budapest',
        'locale' => 'hu',
    ], $attributes));
}

/**
 * A confirmed booking for a customer, booked well before it starts (so it is owed a
 * 24h reminder). Times are UTC, as stored.
 */
function reminderBooking(Tenant $tenant, string $startsAt, array $attributes = []): Booking
{
    $customer = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Teszt Elek',
        'email' => 'customer'.Str::random(6).'@acme.test',
    ]);
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Svédmasszázs']);

    return Booking::factory()->forTenant($tenant)->create(array_merge([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Confirmed,
        'starts_at' => $startsAt,
        'ends_at' => Carbon::parse($startsAt)->addHour(),
        'created_at' => Carbon::parse($startsAt)->subWeek(),
    ], $attributes));
}

function reminderLogs(Booking $booking): int
{
    return NotificationLog::withoutGlobalScopes()
        ->where('type', NotificationType::Reminder24h)
        ->where('dedupe_key', 'booking:'.$booking->id.':reminder_24h')
        ->count();
}

it('reminds the customer of a booking starting within the next 24 hours', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $tenant = reminderTenant();
    $booking = reminderBooking($tenant, '2026-09-02 06:00:00');

    // Creating a confirmed booking already mailed its confirmation — start clean so
    // the assertions below cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    Notification::assertSentTo($booking->customer, BookingReminderNotification::class);
    expect(reminderLogs($booking))->toBe(1);
});

it('reminds exactly once, however often the hourly job runs', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $tenant = reminderTenant();
    $booking = reminderBooking($tenant, '2026-09-02 06:00:00');

    // A confirmed booking mails its confirmation on create — start clean so the
    // assertions cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    // The next hourly run re-selects the same booking — the claim makes it a no-op.
    Carbon::setTestNow('2026-09-01 09:00:00');
    $this->artisan('bookings:remind')->assertSuccessful();

    Notification::assertSentTimes(BookingReminderNotification::class, 1);
    expect(reminderLogs($booking))->toBe(1);
});

it('does not remind a booking that is still more than 24 hours out', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $tenant = reminderTenant();
    $booking = reminderBooking($tenant, '2026-09-03 06:00:00');

    // A confirmed booking mails its confirmation on create — start clean so the
    // assertions cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    Notification::assertNothingSent();
    expect(reminderLogs($booking))->toBe(0);
});

it('does not remind a booking that has already started', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $tenant = reminderTenant();
    $booking = reminderBooking($tenant, '2026-09-01 07:00:00');

    // A confirmed booking mails its confirmation on create — start clean so the
    // assertions cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    Notification::assertNothingSent();
    expect(reminderLogs($booking))->toBe(0);
});

it('only reminds a booking that still holds its slot', function (BookingStatus $status) {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $tenant = reminderTenant();
    $booking = reminderBooking($tenant, '2026-09-02 06:00:00', ['status' => $status]);

    // A confirmed booking mails its confirmation on create — start clean so the
    // assertions cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    Notification::assertNothingSent();
    expect(reminderLogs($booking))->toBe(0);
})->with([
    'requested (not yet approved)' => BookingStatus::Requested,
    'pending payment' => BookingStatus::PendingPayment,
    'canceled' => BookingStatus::Canceled,
]);

it('does not remind about a booking made inside the 24-hour window', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $tenant = reminderTenant();

    // Booked an hour ago, 23 hours before it starts — the customer picked this slot
    // just now and their confirmation already carries the time.
    $booking = reminderBooking($tenant, '2026-09-02 06:00:00', [
        'created_at' => '2026-09-01 07:00:00',
    ]);

    // A confirmed booking mails its confirmation on create — start clean so the
    // assertions cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    Notification::assertNothingSent();
    expect(reminderLogs($booking))->toBe(0);
});

it('keeps the 24-hour window and the local time straight across a DST switch', function () {
    // Europe/Budapest springs forward on 2026-03-29 (CET +1 → CEST +2).
    // "Now" is 2026-03-28 10:00 local (09:00 UTC); the booking starts the next morning
    // at 10:00 LOCAL, which is 08:00 UTC — 23 hours away, so it is due a reminder.
    Carbon::setTestNow('2026-03-28 09:00:00');
    $tenant = reminderTenant();
    $booking = reminderBooking($tenant, '2026-03-29 08:00:00');

    // A confirmed booking mails its confirmation on create — start clean so the
    // assertions cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    expect(reminderLogs($booking))->toBe(1);

    // The lost hour must not shift the customer's appointment: the mail states the
    // tenant-local wall-clock time, not the UTC instant.
    $mail = (new BookingReminderNotification($booking->fresh(), $tenant))->toMail($booking->customer);
    $lines = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));

    expect($lines)->toContain('2026-03-29 10:00')
        ->and($lines)->toContain('Svédmasszázs');
});

it('does not remind on behalf of a suspended tenant', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $tenant = reminderTenant(['status' => TenantStatus::Suspended]);
    $booking = reminderBooking($tenant, '2026-09-02 06:00:00');

    // A confirmed booking mails its confirmation on create — start clean so the
    // assertions cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    Notification::assertNothingSent();
    expect(reminderLogs($booking))->toBe(0);
});

it('does not let an archived tenant’s booking kill the tenant-less reminder run', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $archived = reminderTenant(['slug' => 'gone']);
    $archivedBooking = reminderBooking($archived, '2026-09-02 06:00:00');

    $live = reminderTenant();
    $liveBooking = reminderBooking($live, '2026-09-02 06:00:00');

    // Archiving soft-deletes the tenant; its bookings stay behind, and their plain
    // `tenant` relation now resolves to null. One such row must not take the whole
    // run (i.e. every other tenant's reminders) down with it.
    $archived->delete();

    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    expect(reminderLogs($archivedBooking))->toBe(0)
        ->and(reminderLogs($liveBooking))->toBe(1);
    Notification::assertSentTimes(BookingReminderNotification::class, 1);
});

it('reminds each tenant’s customers in their own booking, tenant-less run', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $acme = reminderTenant();
    $other = reminderTenant(['slug' => 'other', 'name' => 'Other Kft']);

    $acmeBooking = reminderBooking($acme, '2026-09-02 06:00:00');
    $otherBooking = reminderBooking($other, '2026-09-02 07:00:00');

    // A confirmed booking mails its confirmation on create — start clean so the
    // assertions cover only what the scheduled job itself sent.
    Notification::fake();

    $this->artisan('bookings:remind')->assertSuccessful();

    Notification::assertSentTimes(BookingReminderNotification::class, 2);

    // Each customer's mail speaks for THEIR tenant: the run walks many tenants in one
    // loop, so a tenant bleeding into the next one's mail is the failure to guard.
    Notification::assertSentTo(
        $acmeBooking->customer,
        BookingReminderNotification::class,
        fn (BookingReminderNotification $mail) => str_contains(
            (string) $mail->toMail($acmeBooking->customer)->subject,
            'Acme Szalon',
        ),
    );
    Notification::assertSentTo(
        $otherBooking->customer,
        BookingReminderNotification::class,
        fn (BookingReminderNotification $mail) => str_contains(
            (string) $mail->toMail($otherBooking->customer)->subject,
            'Other Kft',
        ),
    );

    $acmeLog = NotificationLog::withoutGlobalScopes()
        ->where('dedupe_key', 'booking:'.$acmeBooking->id.':reminder_24h')->firstOrFail();
    $otherLog = NotificationLog::withoutGlobalScopes()
        ->where('dedupe_key', 'booking:'.$otherBooking->id.':reminder_24h')->firstOrFail();

    // Each claim is filed under its own booking's tenant.
    expect($acmeLog->tenant_id)->toBe($acme->id)
        ->and($otherLog->tenant_id)->toBe($other->id);
});

it('expires a lapsed waitlist offer and emails the next waiter — once', function () {
    Carbon::setTestNow('2026-09-01 08:00:00');
    $tenant = reminderTenant();
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Hatha jóga']);
    $event = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'starts_at' => '2026-09-10 16:00:00',
        'ends_at' => '2026-09-10 18:00:00',
        'capacity' => 10,
        'booked_count' => 9,
        'waitlist_enabled' => true,
    ]);

    $lapsed = WaitlistEntry::factory()->forEvent($event)->position(1)->create([
        'customer_id' => User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'first@acme.test'])->id,
        'service_id' => $service->id,
        'status' => WaitlistStatus::Offered,
        'offered_until' => '2026-09-01 07:00:00',
    ]);
    $next = WaitlistEntry::factory()->forEvent($event)->position(2)->create([
        'customer_id' => User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'second@acme.test'])->id,
        'service_id' => $service->id,
    ]);

    $this->artisan('waitlist:expire-offers')->assertSuccessful();

    expect($lapsed->fresh()->status)->toBe(WaitlistStatus::Expired)
        ->and($next->fresh()->status)->toBe(WaitlistStatus::Offered);

    // The freed place moved to the next waiter — and they were told about it.
    Notification::assertSentTo($next->customer, WaitlistOfferNotification::class);
    Notification::assertSentTimes(WaitlistOfferNotification::class, 1);

    // The following hourly run has nothing left to expire and must not re-mail.
    Carbon::setTestNow('2026-09-01 09:00:00');
    $this->artisan('waitlist:expire-offers')->assertSuccessful();

    Notification::assertSentTimes(WaitlistOfferNotification::class, 1);
    expect(NotificationLog::withoutGlobalScopes()
        ->where('type', NotificationType::WaitlistOffer)
        ->where('dedupe_key', 'waitlist_entry:'.$next->id)
        ->count())->toBe(1);
});
