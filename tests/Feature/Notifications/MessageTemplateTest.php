<?php

use App\Enums\BookingStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Tenancy\TenantManager;

// Tenant-editable email templates (SLO-113): an enabled override renders the mail
// from the tenant's subject/body with placeholders substituted; absent, disabled,
// foreign-tenant or wrong-locale overrides fall back to the built-in default.

afterEach(function () {
    app(TenantManager::class)->forget();
});

function templateTenant(string $slug = 'acme', string $locale = 'hu'): Tenant
{
    return Tenant::factory()->active()->create([
        'slug' => $slug,
        'name' => 'Acme Szalon',
        'timezone' => 'Europe/Budapest',
        'locale' => $locale,
    ]);
}

function templateCustomer(Tenant $tenant): User
{
    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Teszt Elek',
        'email' => 'customer@acme.test',
    ]);
}

function templateBooking(Tenant $tenant, User $customer): Booking
{
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Svédmasszázs']);

    return Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Confirmed,
        'code' => 'ABC123',
        'starts_at' => '2026-09-01 08:00:00',
        'ends_at' => '2026-09-01 09:00:00',
    ]);
}

it('renders the mail from an enabled tenant override with placeholders substituted', function () {
    $tenant = templateTenant();
    $customer = templateCustomer($tenant);
    $booking = templateBooking($tenant, $customer);

    MessageTemplate::factory()->forTenant($tenant)->forType(NotificationType::BookingConfirmed)->create([
        'locale' => 'hu',
        'subject' => 'Köszönjük a foglalást, :name! – :tenant',
        'body' => "A(z) :service foglalásod kódja :code.\nIdőpont: :when.",
    ]);

    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail($customer);

    expect($mail->subject)->toBe('Köszönjük a foglalást, Teszt Elek! – Acme Szalon')
        ->and($mail->greeting)->toBe('Szia Teszt Elek!')
        ->and($mail->introLines)->toContain('A(z) Svédmasszázs foglalásod kódja ABC123.')
        ->and($mail->introLines)->toContain('Időpont: 2026-09-01 10:00.') // tenant tz (UTC+2)
        ->and($mail->actionText)->toBe(__('app.mail.booking_confirmed.action')) // CTA stays fixed
        ->and($mail->actionUrl)->toContain('acme.'.config('tenancy.central_domain').'/booked/ABC123');
});

it('falls back to the built-in default when the tenant has no override', function () {
    $tenant = templateTenant();
    $customer = templateCustomer($tenant);
    $booking = templateBooking($tenant, $customer);

    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail($customer);

    expect($mail->subject)->toBe(__('app.mail.booking_confirmed.subject', ['tenant' => 'Acme Szalon']))
        ->and($mail->introLines)->toContain(__('app.mail.booking_confirmed.code', ['code' => 'ABC123']));
});

it('ignores a disabled override', function () {
    $tenant = templateTenant();
    $customer = templateCustomer($tenant);
    $booking = templateBooking($tenant, $customer);

    MessageTemplate::factory()->forTenant($tenant)->forType(NotificationType::BookingConfirmed)->disabled()->create([
        'locale' => 'hu',
        'subject' => 'NEM EZ – :tenant',
    ]);

    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail($customer);

    expect($mail->subject)->toBe(__('app.mail.booking_confirmed.subject', ['tenant' => 'Acme Szalon']));
});

it('does not use another tenant\'s override', function () {
    $tenant = templateTenant('acme');
    $customer = templateCustomer($tenant);
    $booking = templateBooking($tenant, $customer);

    $other = templateTenant('other');
    MessageTemplate::factory()->forTenant($other)->forType(NotificationType::BookingConfirmed)->create([
        'locale' => 'hu',
        'subject' => 'IDEGEN TENANT – :tenant',
    ]);

    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail($customer);

    expect($mail->subject)->toBe(__('app.mail.booking_confirmed.subject', ['tenant' => 'Acme Szalon']));
});

it('does not use an override for a different locale', function () {
    $tenant = templateTenant('acme', 'hu');
    $customer = templateCustomer($tenant);
    $booking = templateBooking($tenant, $customer);

    MessageTemplate::factory()->forTenant($tenant)->forType(NotificationType::BookingConfirmed)->create([
        'locale' => 'en',
        'subject' => 'ENGLISH – :tenant',
    ]);

    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail($customer);

    expect($mail->subject)->toBe(__('app.mail.booking_confirmed.subject', ['tenant' => 'Acme Szalon']));
});

it('does not use an override for a different channel', function () {
    $tenant = templateTenant();
    $customer = templateCustomer($tenant);
    $booking = templateBooking($tenant, $customer);

    MessageTemplate::factory()->forTenant($tenant)->forType(NotificationType::BookingConfirmed)->create([
        'locale' => 'hu',
        'channel' => NotificationChannel::Sms,
        'subject' => 'SMS – :tenant',
    ]);

    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail($customer);

    expect($mail->subject)->toBe(__('app.mail.booking_confirmed.subject', ['tenant' => 'Acme Szalon']));
});
