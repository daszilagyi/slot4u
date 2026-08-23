<?php

use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\GuestRecipient;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| mail:deliverability-test (SLO-169)
|--------------------------------------------------------------------------
|
| The command exists so the mail-tester score can be re-measured on demand
| against a message the system genuinely sends. Two properties carry that
| promise and are what these tests defend: the message on the wire is the real
| booking confirmation, and running it writes nothing — otherwise re-measuring
| production would mean leaving test bookings in a customer's data.
|
*/

/** Undo the suite-wide Notification::fake() so a real message reaches the array transport. */
function sendNotificationsForReal(): void
{
    Notification::swap(new ChannelManager(app()));
}

it('sends the real booking confirmation to the given address', function () {
    Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme Stúdió']);

    $this->artisan('mail:deliverability-test', ['recipient' => 'probe@mail-tester.test'])
        ->assertSuccessful();

    Notification::assertSentTo(
        new GuestRecipient('probe@mail-tester.test'),
        BookingConfirmedNotification::class,
    );
});

it('leaves nothing behind — no booking, no delivery log', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->artisan('mail:deliverability-test', ['recipient' => 'probe@mail-tester.test'])
        ->assertSuccessful();

    // A measurement that pollutes what it measures is worse than no measurement:
    // this is what makes the command safe to run against production.
    expect(Booking::withoutGlobalScopes()->count())->toBe(0)
        ->and(NotificationLog::withoutGlobalScopes()->count())->toBe(0);
});

it('puts a genuine message on the wire, in the tenant name, from the platform address', function () {
    sendNotificationsForReal();
    config()->set('mail.from.address', 'no-reply@slot4u.hu');
    Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme Stúdió']);

    $this->artisan('mail:deliverability-test', ['recipient' => 'probe@mail-tester.test'])
        ->assertSuccessful();

    $messages = Mail::getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1);

    $sent = $messages[0]->getOriginalMessage();

    expect($sent->getTo()[0]->getAddress())->toBe('probe@mail-tester.test')
        // From stays the platform address on purpose (docs/11 §8): sending as the
        // tenant's own domain is what fails SPF/DKIM, and that failure is exactly
        // what this command measures.
        ->and($sent->getFrom()[0]->getAddress())->toBe('no-reply@slot4u.hu')
        ->and($sent->getSubject())->toContain('Acme Stúdió');
});

it('sends immediately instead of queueing, so the exit code carries the result', function () {
    sendNotificationsForReal();
    Queue::fake();
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->artisan('mail:deliverability-test', ['recipient' => 'probe@mail-tester.test'])
        ->assertSuccessful();

    // The notification is ShouldQueue and the host drains the queue from a
    // once-a-minute cron. Queued, the failure of a delivery test would surface in
    // a worker log a minute later rather than in front of the operator.
    Queue::assertNothingPushed();
    expect(Mail::getSymfonyTransport()->messages())->toHaveCount(1);
});

it('refuses an argument that is not an email address', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->artisan('mail:deliverability-test', ['recipient' => 'not-an-address'])
        ->expectsOutputToContain('Not an email address')
        ->assertFailed();

    Notification::assertNothingSent();
});

it('will not speak for a suspended or archived tenant', function () {
    Tenant::factory()->suspended()->create(['slug' => 'suspended-co']);
    Tenant::factory()->archived()->create(['slug' => 'archived-co']);

    $this->artisan('mail:deliverability-test', ['recipient' => 'probe@mail-tester.test'])
        ->assertFailed();

    Notification::assertNothingSent();
});

it('renders as the named tenant, and fails on an unknown slug', function () {
    Tenant::factory()->active()->create(['slug' => 'first-co', 'name' => 'Első']);
    Tenant::factory()->active()->create(['slug' => 'second-co', 'name' => 'Második']);

    $this->artisan('mail:deliverability-test', [
        'recipient' => 'probe@mail-tester.test',
        '--tenant' => 'second-co',
    ])->assertSuccessful();

    Notification::assertSentTo(
        new GuestRecipient('probe@mail-tester.test'),
        BookingConfirmedNotification::class,
        function (BookingConfirmedNotification $notification, array $channels, object $notifiable) {
            return str_contains($notification->toMail($notifiable)->subject ?? '', 'Második');
        },
    );

    $this->artisan('mail:deliverability-test', [
        'recipient' => 'probe@mail-tester.test',
        '--tenant' => 'no-such-tenant',
    ])->assertFailed();
});
