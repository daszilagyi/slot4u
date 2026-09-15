<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use App\Services\Mail\MailBrandStore;
use App\Support\Mail\MailBrand;
use App\Tenancy\TenantManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

/*
 * The superadmin mail brand (SLO-245, docs/27): header colour, button colour,
 * background, footer and logo for every system email, with a live preview.
 * One save must reach slot4u's own mail AND every tenant's customer mail.
 */

beforeEach(function () {
    Storage::fake('public');
});

afterEach(function () {
    app(TenantManager::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A valid form payload, overridable per test. */
function mailBrandPayload(array $overrides = []): array
{
    return array_merge([
        'header_background' => '#5A2A82',
        'button_background' => '#E0457B',
        'canvas' => '#FAF5FF',
        'footer_text' => 'GlamOps Kft. · 1111 Budapest',
    ], $overrides);
}

/** A real slot4u mail, rendered as the channel renders it. */
function mailBrandPlatformHtml(): string
{
    return (string) (new VerifyEmail)->toMail(User::factory()->unverified()->create())->render();
}

// --- Access ------------------------------------------------------------------

it('shows the built-in brand to the superadmin', function () {
    $this->actingAs(superAdmin())
        ->get(superUrl('/emails/design'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/MailBrand/Edit')
            ->where('brand.header_background', '#0D1B2A')
            ->where('brand.button_background', '#F4B942')
            ->where('brand.has_logo', false)
            ->where('customised', false)
            // The switch to the texts page names how many mails it holds (SLO-258).
            ->where('mail_count', 19));
});

it('keeps a tenant admin out of the mail brand, even holding every permission', function () {
    $this->seed(PermissionSeeder::class);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);

    $this->actingAs($admin)->get(superUrl('/emails/design'))->assertForbidden();
    $this->actingAs($admin)->post(superUrl('/emails/design'), mailBrandPayload())->assertForbidden();
    $this->actingAs($admin)->post(superUrl('/emails/design/preview'), mailBrandPayload(['sample' => 'platform']))->assertForbidden();

    // The second lock, independent of the host.
    expect($admin->can('manage', PlatformSetting::class))->toBeFalse()
        ->and(PlatformSetting::query()->count())->toBe(0);
});

it('sends a guest to the login', function () {
    $this->get(superUrl('/emails/design'))->assertRedirectContains('/login');
});

// --- Saving reaches every mail -------------------------------------------------

it('saves the brand, audits it, and the next slot4u mail goes out in it', function () {
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(superUrl('/emails/design'), mailBrandPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $html = mailBrandPlatformHtml();

    expect($html)->toContain('background-color: #5A2A82')
        ->toContain('background-color: #E0457B')
        ->toContain('background-color: #FAF5FF')
        ->toContain('GlamOps Kft. · 1111 Budapest');

    $log = AuditLog::query()->latest('id')->sole();
    expect($log->action)->toBe(AuditAction::MailBrandUpdated->value)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->tenant_id)->toBeNull()
        ->and($log->old_values['header_background'])->toBe('#0D1B2A')
        ->and($log->new_values['header_background'])->toBe('#5A2A82');
});

it('puts every tenant customer mail in the saved brand too', function () {
    $this->actingAs(superAdmin())->post(superUrl('/emails/design'), mailBrandPayload());

    $tenant = Tenant::factory()->active()->create(['slug' => 'glam', 'name' => 'GlamZone']);
    app(TenantManager::class)->set($tenant);
    $customer = User::factory()->create(['tenant_id' => $tenant->id]);

    $html = (string) (new MessageReceivedNotification($tenant))->toMail($customer)->render();

    expect($html)->toContain('background-color: #5A2A82')
        ->toContain('GlamOps Kft. · 1111 Budapest')
        ->toContain('GlamZone');
});

it('picks readable text for whatever header and button colour is chosen', function () {
    expect(MailBrand::readableTextOn('#F4B942'))->toBe('#0D1B2A')   // default yellow keeps navy
        ->and(MailBrand::readableTextOn('#0D1B2A'))->toBe('#FFFFFF') // navy header, white text
        ->and(MailBrand::readableTextOn('#777777'))->toBe('#000000'); // neither white nor navy reach 4.5

    $this->actingAs(superAdmin())->post(superUrl('/emails/design'), mailBrandPayload(['button_background' => '#FFE066']));

    expect(app(MailBrand::class)->buttonText)->toBe('#0D1B2A');
});

// --- Validation ---------------------------------------------------------------

it('refuses a background the footer text cannot be read on', function () {
    $this->actingAs(superAdmin())
        ->post(superUrl('/emails/design'), mailBrandPayload(['canvas' => '#333333']))
        ->assertSessionHasErrors('canvas');

    expect(PlatformSetting::query()->count())->toBe(0)
        ->and(mailBrandPlatformHtml())->toContain('background-color: #F5F7FA');
});

it('refuses a colour that is not a six-digit hex', function (string $value) {
    $this->actingAs(superAdmin())
        ->post(superUrl('/emails/design'), mailBrandPayload(['header_background' => $value]))
        ->assertSessionHasErrors('header_background');
})->with(['red', '#FFF', '#12345G', '5A2A82', '#5A2A82; color: red']);

it('refuses an SVG logo, which mail clients drop', function () {
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"/>');

    $this->actingAs(superAdmin())
        ->post(superUrl('/emails/design'), mailBrandPayload(['logo' => $svg]))
        ->assertSessionHasErrors('logo');
});

// --- Logo lifecycle -----------------------------------------------------------

it('stores an uploaded logo, replaces it, and falls back to the slot4u tile on removal', function () {
    $admin = superAdmin();

    $this->actingAs($admin)->post(superUrl('/emails/design'), mailBrandPayload([
        'logo' => UploadedFile::fake()->image('first.png', 120, 120),
    ]))->assertSessionHasNoErrors();

    $first = app(MailBrandStore::class)->settings()->logoPath;
    expect($first)->toStartWith('platform/mail/');
    Storage::disk('public')->assertExists($first);
    expect(mailBrandPlatformHtml())->toContain('src="'.Storage::disk('public')->url($first).'"');

    $this->actingAs($admin)->post(superUrl('/emails/design'), mailBrandPayload([
        'logo' => UploadedFile::fake()->image('second.jpg', 120, 120),
    ]));

    $second = app(MailBrandStore::class)->settings()->logoPath;
    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $this->actingAs($admin)->post(superUrl('/emails/design'), mailBrandPayload(['remove_logo' => true]));

    expect(app(MailBrandStore::class)->settings()->logoPath)->toBeNull()
        ->and(app(MailBrand::class)->logoUrl)->toBe(MailBrand::defaultLogoUrl());
    Storage::disk('public')->assertMissing($second);
});

it('keeps the stored logo when a later save does not touch it', function () {
    $admin = superAdmin();
    $this->actingAs($admin)->post(superUrl('/emails/design'), mailBrandPayload([
        'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
    ]));
    $path = app(MailBrandStore::class)->settings()->logoPath;

    $this->actingAs($admin)->post(superUrl('/emails/design'), mailBrandPayload(['canvas' => '#FFFFFF']));

    expect(app(MailBrandStore::class)->settings()->logoPath)->toBe($path);
    Storage::disk('public')->assertExists($path);
});

// --- Reset --------------------------------------------------------------------

it('resets to the built-in brand, deleting the uploaded logo, and audits it', function () {
    $admin = superAdmin();
    $this->actingAs($admin)->post(superUrl('/emails/design'), mailBrandPayload([
        'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
    ]));
    $path = app(MailBrandStore::class)->settings()->logoPath;

    $this->actingAs($admin)->delete(superUrl('/emails/design'))->assertRedirect();

    expect(PlatformSetting::query()->count())->toBe(0)
        ->and(mailBrandPlatformHtml())->toContain('background-color: #0D1B2A')
        ->not->toContain('GlamOps Kft.')
        ->and(AuditLog::query()->latest('id')->first()->action)->toBe(AuditAction::MailBrandReset->value);
    Storage::disk('public')->assertMissing($path);
});

// --- Preview ------------------------------------------------------------------

it('previews a draft in the real mail frame without saving it', function () {
    $response = $this->actingAs(superAdmin())
        ->postJson(superUrl('/emails/design/preview'), mailBrandPayload(['sample' => 'platform']))
        ->assertOk();

    expect($response->json('html'))->toContain('background-color: #5A2A82')
        ->toContain('GlamOps Kft. · 1111 Budapest')
        // The superadmin host's CSP only allows data: images from elsewhere.
        ->toContain('src="data:image/png;base64,')
        ->and($response->json('footer_contrast_ok'))->toBeTrue()
        ->and(PlatformSetting::query()->count())->toBe(0)
        ->and(mailBrandPlatformHtml())->toContain('background-color: #0D1B2A');
});

it('previews a poor background with the warning instead of refusing to draw it', function () {
    $response = $this->actingAs(superAdmin())
        ->postJson(superUrl('/emails/design/preview'), mailBrandPayload(['canvas' => '#333333', 'sample' => 'platform']))
        ->assertOk();

    expect($response->json('footer_contrast_ok'))->toBeFalse()
        ->and($response->json('html'))->toContain('background-color: #333333');
});

it('previews a tenant mail with the tenant in the header', function () {
    $response = $this->actingAs(superAdmin())
        ->postJson(superUrl('/emails/design/preview'), mailBrandPayload(['sample' => 'tenant']))
        ->assertOk();

    expect($response->json('html'))->toMatch(
        '/class="header"(?:(?!<\/td>).)*'.preg_quote(__('app.super.mail_brand.preview.sample_tenant'), '/').'/s',
    );
});

it('previews a freshly chosen logo before it is saved', function () {
    $logo = UploadedFile::fake()->image('draft.jpg', 80, 80);

    $response = $this->actingAs(superAdmin())
        ->post(superUrl('/emails/design/preview'), mailBrandPayload(['sample' => 'platform', 'logo' => $logo]), ['Accept' => 'application/json'])
        ->assertOk();

    expect($response->json('html'))->toContain('src="data:image/jpeg;base64,')
        ->and(app(MailBrandStore::class)->settings()->logoPath)->toBeNull();
});

it('leaves the stored brand bound after a preview in the same request cycle', function () {
    $this->actingAs(superAdmin())
        ->postJson(superUrl('/emails/design/preview'), mailBrandPayload(['sample' => 'platform']));

    expect(app(MailBrand::class)->headerBackground)->toBe('#0D1B2A');
});

// --- Resilience ---------------------------------------------------------------

it('still sends mail in the built-in brand when the settings table is unreadable', function () {
    // A deploy between the code switch and the migration: mail must not die.
    Schema::drop('platform_settings');

    expect(mailBrandPlatformHtml())->toContain('background-color: #0D1B2A');
});
