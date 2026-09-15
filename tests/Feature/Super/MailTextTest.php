<?php

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\PlatformMailText;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Notifications\StaffInvitationNotification;
use App\Notifications\TenantArchivedNotification;
use App\Services\Mail\MailTextCatalog;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

/*
 * The superadmin email text editor (SLO-246, docs/27 §5): slot4u's own mails,
 * and the base text of the customer mails. Resolution order for a customer
 * mail: tenant override → the superadmin's text → the lang default.
 */

afterEach(function () {
    app(TenantManager::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function mailTextTenant(string $slug = 'acme', string $locale = 'hu'): Tenant
{
    return Tenant::factory()->active()->create([
        'slug' => $slug,
        'name' => 'Acme Szalon',
        'timezone' => 'Europe/Budapest',
        'locale' => $locale,
    ]);
}

function mailTextBooking(Tenant $tenant, User $customer, string $code = 'ABC123', string $startsAt = '2026-09-01 08:00:00'): Booking
{
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Svédmasszázs']);

    return Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Confirmed,
        'code' => $code,
        'starts_at' => $startsAt,
        'ends_at' => Carbon::parse($startsAt)->addHour(),
    ]);
}

function mailTextConfirmation(Tenant $tenant): string
{
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Teszt Elek']);
    $mail = (new BookingConfirmedNotification(mailTextBooking($tenant, $customer), $tenant))->toMail($customer);

    return $mail->subject."\n".implode("\n", [...$mail->introLines, ...$mail->outroLines]);
}

// --- Access ------------------------------------------------------------------

it('lists every editable mail with its default for the superadmin', function () {
    $this->actingAs(superAdmin())
        ->get(superUrl('/emails/templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/MailTexts/Index')
            ->has('mails', 18)
            ->where('mails.0.key', 'verify_email')
            ->where('mails.0.group', 'platform')
            ->where('mails.0.has_outro', true)
            ->where('mails.0.default.subject', 'Erősítsd meg az email címed')
            ->where('mails.0.stored', null)
            ->where('mails.0.variables', ['name', 'count'])
            ->where('mails.6.key', 'tenant_archived')
            ->where('mails.6.has_button', false)
            ->where('mails.10.key', 'booking_confirmed')
            ->where('mails.10.group', 'customer'));
});

it('keeps a tenant admin out of the mail texts, even holding every permission', function () {
    $this->seed(PermissionSeeder::class);
    $tenant = mailTextTenant();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);

    $payload = ['subject' => 'x', 'body' => 'y'];

    $this->actingAs($admin)->get(superUrl('/emails/templates'))->assertForbidden();
    $this->actingAs($admin)->put(superUrl('/emails/templates/verify_email'), $payload)->assertForbidden();
    $this->actingAs($admin)->post(superUrl('/emails/templates/verify_email/preview'), $payload)->assertForbidden();
    $this->actingAs($admin)->delete(superUrl('/emails/templates/verify_email'))->assertForbidden();

    expect($admin->can('manage', PlatformMailText::class))->toBeFalse()
        ->and(PlatformMailText::query()->count())->toBe(0);
});

it('sends a guest to the login', function () {
    $this->get(superUrl('/emails/templates'))->assertRedirectContains('/login');
});

it('answers an unknown or non-editable mail with 404', function (string $key) {
    $admin = superAdmin();

    $this->actingAs($admin)->put(superUrl("/emails/templates/{$key}"), ['subject' => 'x', 'body' => 'y'])->assertNotFound();
    $this->actingAs($admin)->post(superUrl("/emails/templates/{$key}/preview"), ['subject' => 'x', 'body' => 'y'])->assertNotFound();
    $this->actingAs($admin)->delete(superUrl("/emails/templates/{$key}"))->assertNotFound();
})->with(['nonsense', 'payment_success', 'customer_message']);

// --- slot4u's own mails -------------------------------------------------------

it('saves a platform mail, audits it, and the next mail goes out with it', function () {
    $admin = superAdmin();
    $user = User::factory()->unverified()->create(['name' => 'Kiss Péter']);

    // Read once first, so the cached (empty) set has to be forgotten by the save.
    expect((new VerifyEmail)->toMail($user)->subject)->toBe('Erősítsd meg az email címed');

    $this->actingAs($admin)
        ->put(superUrl('/emails/templates/verify_email'), [
            'subject' => 'Üdv a fedélzeten, :name',
            'body' => "Még **egy lépés** van hátra.\n\n- kattints a gombra",
            'outro' => 'A link :count percig él.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $mail = (new VerifyEmail)->toMail($user);

    expect($mail->subject)->toBe('Üdv a fedélzeten, Kiss Péter')
        ->and($mail->introLines)->toBe(['Még **egy lépés** van hátra.', '- kattints a gombra'])
        ->and($mail->outroLines)->toBe(['A link 60 percig él.'])
        // Not editable: the greeting and the button keep coming from code.
        ->and($mail->greeting)->toBe('Szia Kiss Péter!')
        ->and($mail->actionText)->toBe('Email cím megerősítése')
        ->and($mail->actionUrl)->toContain('/email/verify/');

    // The frame renders the simple formatting.
    // (The frame inlines its CSS, so the tags carry style attributes.)
    expect((string) $mail->render())
        ->toMatch('#<strong[^>]*>egy lépés</strong>#')
        ->toMatch('#<li[^>]*>kattints a gombra</li>#');

    $log = AuditLog::query()->latest('id')->sole();
    expect($log->action)->toBe(AuditAction::MailTextUpdated->value)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->tenant_id)->toBeNull()
        ->and($log->old_values['subject'])->toBe('Erősítsd meg az email címed')
        ->and($log->new_values['subject'])->toBe('Üdv a fedélzeten, :name');
});

it('renders a platform mail from its lang default, line for line, until edited', function () {
    $tenant = mailTextTenant();
    $invitee = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Nagy Éva']);
    $mail = (new StaffInvitationNotification($tenant, 'token-123'))->toMail($invitee);

    expect($mail->subject)->toBe('Meghívás – Acme Szalon')
        ->and($mail->introLines)->toBe([__('app.mail.staff_invitation.intro', ['tenant' => 'Acme Szalon'])])
        ->and($mail->outroLines)->toBe([__('app.mail.staff_invitation.outro')]);
});

it('stores no after-button text for a mail that has no button', function () {
    $this->actingAs(superAdmin())
        ->put(superUrl('/emails/templates/tenant_archived'), [
            'subject' => ':tenant archiválva',
            'body' => 'Törlés napja: :date',
            'outro' => 'ezt senki nem látná',
        ])
        ->assertSessionHasNoErrors();

    expect(PlatformMailText::query()->sole()->outro)->toBeNull();

    $tenant = mailTextTenant();
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $mail = (new TenantArchivedNotification($tenant, Carbon::parse('2026-12-14 12:00')))->toMail($admin);

    expect($mail->subject)->toBe('Acme Szalon archiválva')
        ->and($mail->introLines)->toHaveCount(1)
        ->and($mail->introLines[0])->toStartWith('Törlés napja: ')
        ->and($mail->actionText)->toBeNull();
});

// --- Customer mails: the resolution order ------------------------------------------

it('gives every tenant without an override the superadmin base text', function () {
    $this->actingAs(superAdmin())
        ->put(superUrl('/emails/templates/booking_confirmed'), [
            'subject' => 'Várunk, :name! (:tenant)',
            'body' => "Kódod: :code\nMikor: :when",
        ])
        ->assertSessionHasNoErrors();

    $text = mailTextConfirmation(mailTextTenant());

    expect($text)->toContain('Várunk, Teszt Elek! (Acme Szalon)')
        ->toContain('Kódod: ABC123')
        ->toContain('Mikor: 2026-09-01 10:00')
        // The notification's own closing line still follows the edited body.
        ->toContain('Acme Szalon');
});

it('lets a tenant override win over the superadmin base text', function () {
    $this->actingAs(superAdmin())->put(superUrl('/emails/templates/booking_confirmed'), [
        'subject' => 'Platform tárgy',
        'body' => 'Platform szöveg',
    ]);

    $tenant = mailTextTenant();
    MessageTemplate::factory()->forTenant($tenant)->forType(NotificationType::BookingConfirmed)->create([
        'subject' => 'Saját tárgy',
        'body' => 'Saját szöveg',
        'locale' => 'hu',
    ]);

    $text = mailTextConfirmation($tenant);

    expect($text)->toContain('Saját tárgy')->toContain('Saját szöveg');

    foreach (['Platform tárgy', 'Platform szöveg'] as $platform) {
        expect($text)->not->toContain($platform);
    }
});

it('falls back to the lang default for a tenant in another locale', function () {
    $this->actingAs(superAdmin())->put(superUrl('/emails/templates/booking_confirmed'), [
        'subject' => 'Platform tárgy',
        'body' => 'Platform szöveg',
    ]);

    // The superadmin edits the platform locale; an `en` tenant has no row.
    $text = mailTextConfirmation(mailTextTenant('english', 'en'));

    expect($text)->not->toContain('Platform tárgy');
});

it('shows a tenant admin the superadmin base text as the default to start from', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    $this->actingAs(superAdmin())->put(superUrl('/emails/templates/booking_confirmed'), [
        'subject' => 'Platform tárgy',
        'body' => 'Platform szöveg',
    ]);
    $this->flushSession();

    $tenant = mailTextTenant();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('templates.0.key', 'booking_confirmed')
            ->where('templates.0.default.subject', 'Platform tárgy')
            ->where('templates.0.default.body', 'Platform szöveg')
            ->where('templates.1.default.subject', __('app.mail.booking_modified.subject')));
});

it('⚠️ puts the previous time on the "previous" line of an edited reschedule mail', function () {
    // The lang line used `:when` for the OLD time, so any edited wording of this
    // mail — a tenant's, or now the superadmin's saved default — printed the NEW
    // time twice. The variable has its own name now.
    $body = app(MailTextCatalog::class)->default('booking_modified')->body;
    expect($body)->toContain(':previous');

    $this->actingAs(superAdmin())->put(superUrl('/emails/templates/booking_modified'), [
        'subject' => 'Módosítva',
        'body' => $body,
    ])->assertSessionHasNoErrors();

    $tenant = mailTextTenant();
    $customer = User::factory()->create(['tenant_id' => $tenant->id]);
    $original = mailTextBooking($tenant, $customer, 'OLD111', '2026-09-01 08:00:00');
    $moved = mailTextBooking($tenant, $customer, 'NEW222', '2026-09-03 12:00:00');

    $lines = (new BookingRescheduledNotification($moved, $original, $tenant))->toMail($customer)->introLines;

    expect($lines)->toContain('Korábbi időpont: 2026-09-01 10:00')
        ->toContain('Új időpont: 2026-09-03 14:00');
});

// --- Reset ----------------------------------------------------------------------

it('resets a mail to its default, and audits it', function () {
    $admin = superAdmin();
    $user = User::factory()->unverified()->create();

    $this->actingAs($admin)->put(superUrl('/emails/templates/verify_email'), [
        'subject' => 'Egyedi tárgy',
        'body' => 'Egyedi szöveg',
        'outro' => '',
    ]);
    expect((new VerifyEmail)->toMail($user)->subject)->toBe('Egyedi tárgy');

    $this->actingAs($admin)->delete(superUrl('/emails/templates/verify_email'))->assertRedirect();

    expect(PlatformMailText::query()->count())->toBe(0)
        ->and((new VerifyEmail)->toMail($user)->subject)->toBe('Erősítsd meg az email címed')
        ->and(AuditLog::query()->latest('id')->first()->action)->toBe(AuditAction::MailTextReset->value);
});

it('writes no audit entry when resetting a mail that was never edited', function () {
    $this->actingAs(superAdmin())->delete(superUrl('/emails/templates/verify_email'))->assertRedirect();

    expect(AuditLog::query()->count())->toBe(0);
});

// --- Validation ---------------------------------------------------------------

it('refuses what would reach a customer looking broken', function (array $payload, string $field) {
    $this->actingAs(superAdmin())
        ->put(superUrl('/emails/templates/booking_confirmed'), array_merge(['subject' => 'Tárgy', 'body' => 'Szöveg'], $payload))
        ->assertSessionHasErrors($field);

    expect(PlatformMailText::query()->count())->toBe(0);
})->with([
    'raw html' => [['body' => 'Szia <b>:name</b>'], 'body'],
    'a closing tag' => [['subject' => 'Tárgy </a>'], 'subject'],
    'an image' => [['body' => '![logó](https://example.com/pixel.png)'], 'body'],
    'a variable this mail does not have' => [['body' => 'Összeg: :amount'], 'body'],
    'no subject' => [['subject' => ''], 'subject'],
    'no body' => [['body' => ''], 'body'],
]);

it('accepts links, bold, lists, times and every variable the mail has', function () {
    $this->actingAs(superAdmin())
        ->put(superUrl('/emails/templates/booking_confirmed'), [
            'subject' => 'Foglalás: :code',
            'body' => "**:name**, várunk 10:00-kor!\n- :service\n- :when\n[Térkép](https://maps.example.com) · :tenant",
        ])
        ->assertSessionHasNoErrors();

    expect(PlatformMailText::query()->sole()->key)->toBe('booking_confirmed');
});

// --- Preview --------------------------------------------------------------------

it('previews a draft in the real frame with sample values', function () {
    $response = $this->actingAs(superAdmin())
        ->post(superUrl('/emails/templates/commission_invoice_overdue/preview'), [
            'subject' => 'Lejárt: :period',
            'body' => 'Fizetendő: **:amount**',
            'outro' => 'Köszönjük!',
        ])
        ->assertOk();

    expect($response->json('subject'))->toBe('Lejárt: 2026-08')
        ->and($response->json('html'))->toMatch('#<strong[^>]*>12 500 Ft</strong>#')
        ->toContain('Köszönjük!')
        ->toContain('Számlázás megnyitása');

    // A preview saves nothing.
    expect(PlatformMailText::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('previews a customer mail as the tenant sends it, and shows what saving would refuse', function () {
    $html = $this->actingAs(superAdmin())
        ->post(superUrl('/emails/templates/booking_confirmed/preview'), [
            'subject' => 'Tárgy',
            'body' => 'Kód: :code, ismeretlen: :amount',
        ])
        ->assertOk()
        ->json('html');

    expect($html)->toContain('Kód: K7M2QX, ismeretlen: :amount')
        ->toContain('Minta Szalon')
        ->toContain('Foglalás megtekintése');
});

// --- Failure tolerance -------------------------------------------------------------

it('still sends every mail on its default when the table cannot be read', function () {
    Cache::flush();
    Schema::drop('platform_mail_texts');

    $mail = (new VerifyEmail)->toMail(User::factory()->unverified()->create());

    expect($mail->subject)->toBe('Erősítsd meg az email címed');
});
