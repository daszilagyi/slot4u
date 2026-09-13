<?php

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CreateBooking;
use App\Actions\Customer\PublicContact;
use App\Actions\Waitlist\JoinWaitlist;
use App\Enums\BookingMode;
use App\Enums\WaitlistStatus;
use App\Models\Event;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\GuestRecipient;
use App\Notifications\WaitlistOfferNotification;
use App\Services\Booking\WaitlistService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Guest waitlist places (SLO-228)
|--------------------------------------------------------------------------
|
| A visitor whose email belongs to an account elsewhere joins a waitlist as a
| guest, like on every other public flow (SLO-128). What has to keep working for
| them is the whole loop the waitlist exists for: the offer reaches them, and
| booking the event with that address closes their place and moves the queue on.
|
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

function guestWlTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);
    app(TenantManager::class)->set($tenant);

    return $tenant;
}

/** @return array{0: Service, 1: Event} */
function guestWlEvent(Tenant $tenant, int $capacity = 1, int $bookedCount = 0): array
{
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::EventBased,
        'requires_staff' => false,
    ]);
    $event = Event::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'capacity' => $capacity,
        'booked_count' => $bookedCount,
        'waitlist_enabled' => true,
    ]);

    return [$service, $event];
}

function guestWlContact(string $email = 'vendeg@example.test'): PublicContact
{
    return PublicContact::forGuest('Teszt Vendég', $email, null);
}

it('mails the offer to the guest address when a seat frees up', function () {
    $tenant = guestWlTenant();
    [$service, $event] = guestWlEvent($tenant);
    $booking = app(CreateBooking::class)($service, [
        'event_id' => $event->id, 'customer_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        'party_size' => 1, 'source' => 'admin',
    ]);
    $entry = app(JoinWaitlist::class)($event, guestWlContact());

    app(CancelBooking::class)($booking, reason: 'test');

    expect($entry->fresh()->status)->toBe(WaitlistStatus::Offered);

    Notification::assertSentTo(
        new GuestRecipient('vendeg@example.test', 'Teszt Vendég'),
        WaitlistOfferNotification::class,
        function (WaitlistOfferNotification $notification, array $channels, GuestRecipient $notifiable): bool {
            // The booking recognises the guest by this address alone, so the mail
            // has to say which one to use.
            $mail = $notification->toMail($notifiable);

            return collect($mail->introLines)->merge($mail->outroLines)
                ->contains(fn (string $line): bool => str_contains($line, 'vendeg@example.test'));
        },
    );
});

it('converts the guest place when the guest books the event, and offers the next waiter', function () {
    $tenant = guestWlTenant();
    [$service, $event] = guestWlEvent($tenant, capacity: 2);
    $b1 = app(CreateBooking::class)($service, ['event_id' => $event->id, 'customer_id' => User::factory()->create(['tenant_id' => $tenant->id])->id, 'party_size' => 1, 'source' => 'admin']);
    $b2 = app(CreateBooking::class)($service, ['event_id' => $event->id, 'customer_id' => User::factory()->create(['tenant_id' => $tenant->id])->id, 'party_size' => 1, 'source' => 'admin']);
    $guest = app(JoinWaitlist::class)($event, guestWlContact());
    $next = app(JoinWaitlist::class)($event, User::factory()->create(['tenant_id' => $tenant->id])->id);

    app(CancelBooking::class)($b1, reason: 'test');
    app(CancelBooking::class)($b2, reason: 'test');

    // The guest follows the offer mail to the public page and books as a guest.
    app(CreateBooking::class)($service, [
        'event_id' => $event->id,
        'party_size' => 1,
        'source' => 'online',
        ...guestWlContact()->recordAttributes(),
    ]);

    expect($guest->fresh()->status)->toBe(WaitlistStatus::Converted)
        ->and($next->fresh()->status)->toBe(WaitlistStatus::Offered);
});

it('never converts a customer place from a guest booking that merely types the same email', function () {
    $tenant = guestWlTenant();
    [, $event] = guestWlEvent($tenant, capacity: 1, bookedCount: 1);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'anna@example.test']);
    $entry = app(JoinWaitlist::class)($event, $customer->id);

    $converted = app(WaitlistService::class)->markConverted($event->id, $tenant->id, null, 'anna@example.test');

    expect($converted)->toBe(0)
        ->and($entry->fresh()->status)->toBe(WaitlistStatus::Waiting);
});

it('does not convert a guest place held at another tenant under the same email', function () {
    $other = guestWlTenant('other');
    [, $otherEvent] = guestWlEvent($other, capacity: 1, bookedCount: 1);
    $theirs = app(JoinWaitlist::class)($otherEvent, guestWlContact());

    $tenant = guestWlTenant();

    // ⚠️ No ambient tenant: CreateBooking also runs from queue jobs and the
    // (tenant-less) scheduler, where the global scope is a no-op. With a tenant
    // bound, the scope would hide the other tenant's row and this test would
    // pass without the explicit tenant anchor it exists to guard.
    app(TenantManager::class)->forget();

    // The other tenant's event id, from this tenant: the tenant anchor must hold.
    $converted = app(WaitlistService::class)->markConverted($otherEvent->id, $tenant->id, null, 'vendeg@example.test');

    expect($converted)->toBe(0)
        ->and($theirs->fresh()->status)->toBe(WaitlistStatus::Waiting);
});

it('refuses to list the same guest email twice on one event', function () {
    $tenant = guestWlTenant();
    [, $event] = guestWlEvent($tenant, capacity: 1, bookedCount: 1);

    app(JoinWaitlist::class)($event, guestWlContact());

    expect(fn () => app(JoinWaitlist::class)($event, guestWlContact()))
        ->toThrow(ValidationException::class);

    expect(WaitlistEntry::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('lets a guest and a customer with different addresses queue side by side', function () {
    $tenant = guestWlTenant();
    [, $event] = guestWlEvent($tenant, capacity: 1, bookedCount: 1);

    $guest = app(JoinWaitlist::class)($event, guestWlContact());
    $customer = app(JoinWaitlist::class)($event, User::factory()->create(['tenant_id' => $tenant->id])->id);

    expect($guest->position)->toBe(1)
        ->and($customer->position)->toBe(2);
});
