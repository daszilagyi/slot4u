<?php

use App\Actions\Event\CancelEvent;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\NotificationType;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\BookingCanceledNotification;
use App\Notifications\WaitlistOfferNotification;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Notification;

// Cancelling an announced event cancels the registrants' bookings and notifies
// each of them (SLO-111, docs/04 §3). Notifications are faked globally
// (tests/Pest.php).

afterEach(function () {
    app(TenantManager::class)->forget();
});

function eventCancelTenant(): Tenant
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

function eventCancelService(Tenant $tenant): Service
{
    return Service::factory()->forTenant($tenant)->create([
        'name' => 'Csoportos jóga',
        'booking_mode' => BookingMode::EventBased,
        'capacity' => 20,
        'requires_staff' => false,
    ]);
}

/** A registrant with a confirmed booking on the event. */
function eventRegistrant(Tenant $tenant, Event $event, Service $service, string $email, int $partySize = 1): Booking
{
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'email' => $email]);

    return Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'event_id' => $event->id,
        'booking_mode' => BookingMode::EventBased,
        'status' => BookingStatus::Confirmed,
        'party_size' => $partySize,
    ]);
}

it('cancels every registrant booking and emails each registrant once', function () {
    $tenant = eventCancelTenant();
    $service = eventCancelService($tenant);
    $event = Event::factory()->forTenant($tenant)->withBookings(2)
        ->create(['service_id' => $service->id]);

    $a = eventRegistrant($tenant, $event, $service, 'a@acme.test');
    $b = eventRegistrant($tenant, $event, $service, 'b@acme.test');

    app(CancelEvent::class)($event, false);

    expect($event->fresh()->status)->toBe(EventStatus::Canceled)
        ->and($a->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($b->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($a->fresh()->cancel_reason)->toBe('Az esemény elmaradt.');

    Notification::assertSentTo($a->customer, BookingCanceledNotification::class);
    Notification::assertSentTo($b->customer, BookingCanceledNotification::class);
    Notification::assertSentTimes(BookingCanceledNotification::class, 2);

    expect(NotificationLog::withoutGlobalScopes()
        ->where('type', NotificationType::BookingCanceled)
        ->where('dedupe_key', 'booking:'.$a->id)
        ->count())->toBe(1);
});

it('leaves a terminal booking on the event untouched', function () {
    $tenant = eventCancelTenant();
    $service = eventCancelService($tenant);
    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id]);

    $completed = eventRegistrant($tenant, $event, $service, 'done@acme.test');
    // status is guarded (not mass-assignable) — set it directly.
    $completed->status = BookingStatus::Completed;
    $completed->save();

    app(CancelEvent::class)($event, false);

    expect($completed->fresh()->status)->toBe(BookingStatus::Completed);
    Notification::assertSentTimes(BookingCanceledNotification::class, 0);
});

it('does not offer a freed seat to a waiter on the canceled event', function () {
    $tenant = eventCancelTenant();
    $service = eventCancelService($tenant);
    $event = Event::factory()->forTenant($tenant)->withBookings(20)
        ->create(['service_id' => $service->id, 'capacity' => 20, 'waitlist_enabled' => true]);

    eventRegistrant($tenant, $event, $service, 'seated@acme.test');

    $waiter = WaitlistEntry::factory()->forTenant($tenant)->create([
        'event_id' => $event->id,
        'customer_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        'status' => WaitlistStatus::Waiting,
        'position' => 1,
    ]);

    app(CancelEvent::class)($event, false);

    expect($waiter->fresh()->status)->toBe(WaitlistStatus::Expired);
    Notification::assertSentTimes(WaitlistOfferNotification::class, 0);
});

it('cancels registrants across the series tail when applying to following', function () {
    $tenant = eventCancelTenant();
    $service = eventCancelService($tenant);
    $seriesId = 'series-uuid-1';

    $first = Event::factory()->forTenant($tenant)->withBookings(1)->at('2026-09-01 10:00:00', '2026-09-01 11:00:00')
        ->create(['service_id' => $service->id, 'series_id' => $seriesId]);
    $second = Event::factory()->forTenant($tenant)->withBookings(1)->at('2026-09-08 10:00:00', '2026-09-08 11:00:00')
        ->create(['service_id' => $service->id, 'series_id' => $seriesId]);

    $onFirst = eventRegistrant($tenant, $first, $service, 'first@acme.test');
    $onSecond = eventRegistrant($tenant, $second, $service, 'second@acme.test');

    app(CancelEvent::class)($first, true);

    expect($first->fresh()->status)->toBe(EventStatus::Canceled)
        ->and($second->fresh()->status)->toBe(EventStatus::Canceled)
        ->and($onFirst->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($onSecond->fresh()->status)->toBe(BookingStatus::Canceled);

    Notification::assertSentTimes(BookingCanceledNotification::class, 2);
});
