<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Tenant;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\GuestRecipient;
use App\Notifications\TenantArchivedNotification;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| Who a tenant mail claims to be from (SLO-171)
|--------------------------------------------------------------------------
|
| The live mail-tester run for SLO-169 scored 9.5/10 on deliverability and
| still exposed this: the mail arrives, and it arrives from "slot4u" with no
| Reply-To, while its own last line says "reply to this email". The customer
| believes they wrote to their hairdresser; they wrote to a mailbox called
| no-reply that nobody opens.
|
| So these tests read the ACTUAL headers off the array transport, not the
| MailMessage object. The bug being fixed is precisely one where the intent
| and the wire disagreed.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    config()->set('mail.from.address', 'no-reply@slot4u.hu');
    config()->set('mail.from.name', 'slot4u');
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** Undo the suite-wide Notification::fake() so a real message reaches the array transport. */
function sendTenantMailForReal(): void
{
    Notification::swap(new ChannelManager(app()));
}

/** @param array<string, mixed> $settings */
function mailTenant(array $settings = []): Tenant
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'name' => 'Acme Stúdió',
        'settings' => $settings,
    ]);

    app(TenantManager::class)->set($tenant);

    return $tenant;
}

function identityBookingFor(Tenant $tenant): Booking
{
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
    ]);

    return Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'customer_id' => null,
        'guest_name' => 'Teszt Vendég',
        'guest_email' => 'vendeg@example.test',
        'status' => BookingStatus::Confirmed,
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHour(),
    ]);
}

/** The one message on the wire. */
function sentMessage(): Email
{
    $messages = Mail::getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);

    /** @var Email $email */
    $email = $messages[0]->getOriginalMessage();

    return $email;
}

function sendConfirmation(Tenant $tenant): void
{
    // Built BEFORE the fake is lifted: a booking created straight into
    // `confirmed` mails the customer through its own listener, and that message
    // would sit on the transport alongside the one under test.
    $booking = identityBookingFor($tenant);

    sendTenantMailForReal();

    (new GuestRecipient('vendeg@example.test', 'Teszt Vendég'))
        ->notifyNow(new BookingConfirmedNotification($booking, $tenant));
}

// --- With a reply address ---

it('sends in the tenant name and points replies at the tenant', function () {
    $tenant = mailTenant(['email' => 'foglalas@acme.test']);

    sendConfirmation($tenant);
    $sent = sentMessage();

    // ⚠️ The ADDRESS stays ours. Sending as the tenant's own domain is what
    // fails SPF and DKIM — we hold neither for a domain we do not control — and
    // the 9.5/10 of SLO-169 is exactly what that would throw away.
    expect($sent->getFrom()[0]->getAddress())->toBe('no-reply@slot4u.hu')
        // Only the display name changes, and that is the half a customer reads
        // in their inbox list.
        ->and($sent->getFrom()[0]->getName())->toBe('Acme Stúdió')
        ->and($sent->getReplyTo())->toHaveCount(1)
        ->and($sent->getReplyTo()[0]->getAddress())->toBe('foglalas@acme.test');
});

it('invites a reply only when there is somewhere for it to go', function () {
    $tenant = mailTenant(['email' => 'foglalas@acme.test']);

    sendConfirmation($tenant);

    expect((string) sentMessage()->getHtmlBody())
        ->toContain('válaszolj erre az emailre');
});

// --- Without one ---

it('sends no Reply-To when the tenant has no contact address', function () {
    $tenant = mailTenant([]);

    sendConfirmation($tenant);
    $sent = sentMessage();

    // Still in the tenant's name — that half never depended on a reply address.
    expect($sent->getFrom()[0]->getName())->toBe('Acme Stúdió')
        ->and($sent->getReplyTo())->toBe([]);
});

it('does not ask for a reply it cannot receive', function () {
    // The failure this whole issue is about. A mail whose text invites a reply
    // and whose headers send it to an unattended mailbox is worse than one that
    // says nothing: the customer thinks they have made contact.
    $tenant = mailTenant([]);

    sendConfirmation($tenant);
    $body = (string) sentMessage()->getHtmlBody();

    expect($body)->not->toContain('válaszolj erre az emailre')
        // And it does not merely go quiet — it says where the tenant actually is.
        ->and($body)->toContain('ne válaszolj')
        ->and($body)->toContain('acme.');
});

it('refuses a profile address that is just the platform mailbox', function () {
    // A tenant that typed our own no-reply into its profile would otherwise get
    // a Reply-To promising a human and delivering a mailbox nobody opens. No
    // header at least makes the mail client say so.
    $tenant = mailTenant(['email' => 'NO-REPLY@slot4u.hu']);

    sendConfirmation($tenant);
    $sent = sentMessage();

    expect($sent->getReplyTo())->toBe([])
        ->and((string) $sent->getHtmlBody())->not->toContain('válaszolj erre az emailre');
});

// --- The tenant's own template ---

it('addresses a tenant template override the same way', function () {
    // A tenant writing its own body must not be able to end up with a mail that
    // contradicts its headers — the closing line comes from the same decision.
    $tenant = mailTenant(['email' => 'foglalas@acme.test']);

    MessageTemplate::factory()->forTenant($tenant)->create([
        'key' => NotificationType::BookingConfirmed,
        'locale' => $tenant->locale,
        'enabled' => true,
        'subject' => 'Saját tárgy – :tenant',
        'body' => 'Saját szöveg a vendégnek.',
    ]);

    sendConfirmation($tenant);
    $sent = sentMessage();

    expect($sent->getSubject())->toContain('Saját tárgy')
        ->and($sent->getFrom()[0]->getName())->toBe('Acme Stúdió')
        ->and($sent->getReplyTo()[0]->getAddress())->toBe('foglalas@acme.test')
        ->and((string) $sent->getHtmlBody())->toContain('válaszolj erre az emailre');
});

// --- Platform mail is not tenant mail ---

it('leaves a platform mail speaking for the platform', function () {
    // The commission invoice really does come from slot4u, and a tenant name on
    // it would misstate who is billing whom.
    sendTenantMailForReal();
    $tenant = mailTenant(['email' => 'foglalas@acme.test']);

    (new GuestRecipient('penzugy@acme.test', 'Acme Stúdió'))
        ->notifyNow(new TenantArchivedNotification($tenant, Carbon::now()->addDays(90)));

    expect(sentMessage()->getFrom()[0]->getName())->toBe('slot4u');
});
