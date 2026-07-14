<?php

use App\Enums\BookingStatus;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Tenancy\TenantManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

// Notifications are faked globally (tests/Pest.php); these assert routing +
// idempotency against the fake and drive the delivery listener directly for
// status logging.

afterEach(function () {
    app(TenantManager::class)->forget();
});

/**
 * A tenant + customer + a confirmed booking for them. The model fires
 * BookingCreated on create, so an already-confirmed booking routes its
 * confirmation to the customer.
 */
function confirmedBookingFor(Tenant $tenant, array $attributes = []): Booking
{
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'customer@acme.test', 'name' => 'Teszt Elek']);
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Svédmasszázs']);

    return Booking::factory()->forTenant($tenant)->create(array_merge([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Confirmed,
    ], $attributes));
}

it('routes a confirmation to the customer and claims a log row when a booking is created confirmed', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $booking = confirmedBookingFor($tenant);

    Notification::assertSentTo(
        $booking->customer,
        BookingConfirmedNotification::class,
    );

    $log = NotificationLog::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', NotificationType::BookingConfirmed)
        ->where('dedupe_key', 'booking:'.$booking->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->recipient)->toBe('customer@acme.test')
        ->and($log->channel)->toBe('email')
        ->and($log->status)->toBe(NotificationStatus::Pending);
});

it('does not confirm a requested booking, then sends exactly once when it becomes confirmed', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $booking = confirmedBookingFor($tenant, ['status' => BookingStatus::Requested]);

    // A requested booking has not been confirmed → nothing sent yet.
    Notification::assertNothingSent();
    expect(NotificationLog::withoutGlobalScopes()->where('dedupe_key', 'booking:'.$booking->id)->count())->toBe(0);

    // Approval promotes it to confirmed.
    $booking->update(['status' => BookingStatus::Confirmed]);
    event(new BookingStatusChanged($booking->fresh(), BookingStatus::Requested, BookingStatus::Confirmed));

    // A second confirmed transition (should never happen, but must be idempotent).
    event(new BookingStatusChanged($booking->fresh(), BookingStatus::Requested, BookingStatus::Confirmed));

    Notification::assertSentTimes(BookingConfirmedNotification::class, 1);
    expect(NotificationLog::withoutGlobalScopes()->where('dedupe_key', 'booking:'.$booking->id)->count())->toBe(1);
});

it('does not resend when the same booking-created event fires twice', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $booking = confirmedBookingFor($tenant);

    // Re-dispatch the creation event: the dedup key blocks a second send.
    event(new BookingCreated($booking->fresh()));

    Notification::assertSentTimes(BookingConfirmedNotification::class, 1);
    expect(NotificationLog::withoutGlobalScopes()->where('dedupe_key', 'booking:'.$booking->id)->count())->toBe(1);
});

it('marks the log sent when the notification is delivered', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'customer@acme.test']);
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::Requested,
    ]);

    $log = NotificationLog::factory()->forTenant($tenant)->create([
        'recipient' => 'customer@acme.test',
        'dedupe_key' => 'booking:'.$booking->id,
    ]);

    $notification = (new BookingConfirmedNotification($booking, $tenant))->withNotificationLog($log->id);
    event(new NotificationSent($customer, $notification, 'mail'));

    $log->refresh();
    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->error)->toBeNull();
});

it('records a failed delivery with its error message', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'customer@acme.test']);
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::Requested,
    ]);

    $log = NotificationLog::factory()->forTenant($tenant)->create([
        'recipient' => 'customer@acme.test',
        'dedupe_key' => 'booking:'.$booking->id,
    ]);

    $notification = (new BookingConfirmedNotification($booking, $tenant))->withNotificationLog($log->id);
    event(new NotificationFailed($customer, $notification, 'mail', ['message' => 'SMTP connection refused']));

    $log->refresh();
    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toBe('SMTP connection refused')
        ->and($log->sent_at)->toBeNull();
});

it('ignores delivery events for notifications that do not track delivery', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    // A notification with no RecordsDelivery contract must not touch the log.
    $plain = new class extends BaseNotification {};
    event(new NotificationSent($user, $plain, 'mail'));

    expect(NotificationLog::withoutGlobalScopes()->count())->toBe(0);
});

it('renders the confirmation mail with the code, service and confirmation link', function () {
    config(['app.url' => 'https://slot4u.test']);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme Szalon', 'locale' => 'hu']);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Teszt Elek', 'email' => 'customer@acme.test']);
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Svédmasszázs']);
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Requested,
        'starts_at' => '2026-09-07 07:00:00',
    ]);

    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail($customer);

    expect($mail->subject)->toContain('Acme Szalon')
        ->and($mail->actionUrl)->toBe('https://acme.'.config('tenancy.central_domain').'/booked/'.$booking->code);

    $lines = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));
    expect($lines)->toContain($booking->code)
        ->and($lines)->toContain('Svédmasszázs');
});

it('preserves the notification log id across serialization onto the queue', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = User::factory()->create(['tenant_id' => $tenant->id]);
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::Requested,
    ]);

    $notification = (new BookingConfirmedNotification($booking, $tenant))->withNotificationLog(42);
    $restored = unserialize(serialize($notification));

    expect($restored->notificationLogId())->toBe(42);
});

it('scopes the notification log to the booking tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $booking = confirmedBookingFor($tenant);

    $log = NotificationLog::withoutGlobalScopes()->where('dedupe_key', 'booking:'.$booking->id)->firstOrFail();
    expect($log->tenant_id)->toBe($tenant->id);

    // Bound to another tenant, the global scope hides this row.
    app(TenantManager::class)->set($other);
    expect(NotificationLog::query()->count())->toBe(0);
});
