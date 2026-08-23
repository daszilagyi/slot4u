<?php

use App\Actions\Tenant\SetTenantFeature;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\TenantAnalyticsSettings;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The tenant's own measurement (SLO-56)
|--------------------------------------------------------------------------
|
| Two vendors, two consent categories, and a per-tenant configuration — which is
| three ways for the wrong tag to appear on the wrong page for the wrong person.
| Each of those is a failure nobody sees from the outside, so each gets a test
| that looks at the actual bytes of the page rather than at an internal flag.
|
| One request per test, on purpose: the decision object is scoped to the
| container and a test app is not torn down between calls the way a PHP-FPM
| request is, so a second request in the same test would reuse the first
| request's answer.
|
*/

const TEST_GA4 = 'G-TENANT001';
const TEST_PIXEL = '112233445566778';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** @param  array<string, mixed>  $analytics */
function measuringTenant(array $analytics, string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => $slug,
        'analytics' => $analytics === [] ? null : $analytics,
    ]);

    app(TenantManager::class)->forget();

    return $tenant;
}

function analyticsAdmin(Tenant $tenant): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

/** @param  array<string, bool>  $categories */
function withConsent(array $categories): TestCase
{
    return test()->withUnencryptedCookie(
        (string) config('consent.cookie'),
        (string) json_encode([
            'v' => (string) config('consent.version'),
            'c' => $categories,
        ]),
    );
}

// --- Which tag loads for whom ---

it('loads both of the tenant own tags for a visitor who agreed to both', function () {
    measuringTenant(['ga4_measurement_id' => TEST_GA4, 'meta_pixel_id' => TEST_PIXEL]);

    $response = withConsent(['analytics' => true, 'marketing' => true])
        ->get(tenantHost('acme', '/'))->assertOk();

    $response->assertSee('gtag/js?id='.TEST_GA4, escape: false);
    $response->assertSee(TEST_PIXEL, escape: false);
});

it('counts a visitor who agreed to statistics without retargeting them', function () {
    // The banner asks two questions; answering one is not answering the other.
    // Collapsing the categories would turn the marketing toggle into decoration.
    measuringTenant(['ga4_measurement_id' => TEST_GA4, 'meta_pixel_id' => TEST_PIXEL]);

    $response = withConsent(['analytics' => true, 'marketing' => false])
        ->get(tenantHost('acme', '/'))->assertOk();

    $response->assertSee('gtag/js?id='.TEST_GA4, escape: false);
    $response->assertDontSee('connect.facebook.net', escape: false);
    expect((string) $response->headers->get('Content-Security-Policy'))
        ->not->toContain('facebook');
});

it('retargets a visitor who agreed to marketing without counting them', function () {
    measuringTenant(['ga4_measurement_id' => TEST_GA4, 'meta_pixel_id' => TEST_PIXEL]);

    $response = withConsent(['analytics' => false, 'marketing' => true])
        ->get(tenantHost('acme', '/'))->assertOk();

    $response->assertSee('connect.facebook.net', escape: false);
    $response->assertDontSee('googletagmanager.com', escape: false);
});

it('loads nothing for a visitor who has not decided yet', function () {
    measuringTenant(['ga4_measurement_id' => TEST_GA4, 'meta_pixel_id' => TEST_PIXEL]);

    $response = $this->get(tenantHost('acme', '/'))->assertOk();

    $response->assertDontSee('googletagmanager.com', escape: false);
    $response->assertDontSee('connect.facebook.net', escape: false);
});

it('stops measuring at once when the feature is switched off', function () {
    // Not "keeps whatever ids are stored": switching the feature off has to be a
    // usable off switch, not a lock on the settings screen.
    $tenant = measuringTenant(['ga4_measurement_id' => TEST_GA4, 'meta_pixel_id' => TEST_PIXEL]);
    app(SetTenantFeature::class)($tenant, Feature::Analytics, false);
    app(TenantManager::class)->forget();

    $response = withConsent(['analytics' => true, 'marketing' => true])
        ->get(tenantHost('acme', '/'))->assertOk();

    $response->assertDontSee('googletagmanager.com', escape: false);
    $response->assertDontSee('connect.facebook.net', escape: false);
});

it('never puts one tenant measurement ids on another tenant page', function () {
    measuringTenant(['ga4_measurement_id' => TEST_GA4, 'meta_pixel_id' => TEST_PIXEL], 'acme');
    measuringTenant([], 'other');

    $response = withConsent(['analytics' => true, 'marketing' => true])
        ->get(tenantHost('other', '/'))->assertOk();

    $response->assertDontSee(TEST_GA4, escape: false);
    $response->assertDontSee(TEST_PIXEL, escape: false);
});

it('never puts the platform own tag on a tenant page', function () {
    // The controller/processor line (docs/19 §2). Asserted from the tenant side
    // too, because this is the direction that would leak a tenant's visitors
    // into slot4u's property.
    config(['analytics.platform.ga4_measurement_id' => 'G-PLATFORM1']);
    measuringTenant(['ga4_measurement_id' => TEST_GA4]);

    $response = withConsent(['analytics' => true, 'marketing' => true])
        ->get(tenantHost('acme', '/'))->assertOk();

    $response->assertSee(TEST_GA4, escape: false);
    $response->assertDontSee('G-PLATFORM1', escape: false);
});

it('widens the CSP for exactly the vendors the page loaded', function () {
    measuringTenant(['ga4_measurement_id' => TEST_GA4, 'meta_pixel_id' => TEST_PIXEL]);

    $csp = (string) withConsent(['analytics' => true, 'marketing' => true])
        ->get(tenantHost('acme', '/'))->assertOk()
        ->headers->get('Content-Security-Policy');

    expect($csp)->toContain('https://connect.facebook.net')
        ->and($csp)->toContain('https://www.facebook.com')
        ->and($csp)->toContain('https://www.googletagmanager.com');
});

// --- What the stored value is allowed to be ---

it('refuses to emit a stored id that does not look like one', function () {
    // The value is interpolated into a <script src> and into fbq('init', …). A
    // row written by a seeder, a console command or an older validation rule
    // must not reach the page just because it is already in the database.
    measuringTenant(['ga4_measurement_id' => '"></script><script>alert(1)</script>']);

    $response = withConsent(['analytics' => true, 'marketing' => true])
        ->get(tenantHost('acme', '/'))->assertOk();

    $response->assertDontSee('alert(1)', escape: false);
    $response->assertDontSee('googletagmanager.com', escape: false);
});

it('reads the ids back through the same rule it writes them with', function () {
    $settings = TenantAnalyticsSettings::fromArray([
        'ga4_measurement_id' => ' g-lowercase1 ',
        'meta_pixel_id' => 'not-a-number',
    ]);

    // Trimmed and case-insensitive on the GA4 side (Google prints them upper
    // case but accepts either); a non-numeric pixel id is simply not one.
    expect($settings->ga4MeasurementId)->toBe('g-lowercase1')
        ->and($settings->metaPixelId)->toBeNull();
});

// --- The settings screen ---

it('lets a tenant admin store its own measurement ids', function () {
    $tenant = measuringTenant([]);
    $admin = analyticsAdmin($tenant);

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/settings/analytics'), [
            'ga4_measurement_id' => TEST_GA4,
            'meta_pixel_id' => TEST_PIXEL,
        ])
        ->assertRedirect();

    $settings = TenantAnalyticsSettings::fromArray($tenant->fresh()?->analytics);

    expect($settings->ga4MeasurementId)->toBe(TEST_GA4)
        ->and($settings->metaPixelId)->toBe(TEST_PIXEL);
});

it('stores the measurement column encrypted', function () {
    // It is where the Conversions API access token will live, and a token is only
    // as safe as the least careful write to the row it shares.
    $tenant = measuringTenant([]);
    $admin = analyticsAdmin($tenant);

    $this->actingAs($admin)->post(tenantHost('acme', '/settings/analytics'), [
        'ga4_measurement_id' => TEST_GA4,
        'meta_pixel_id' => null,
    ]);

    $raw = (string) DB::table('tenants')->where('id', $tenant->id)->value('analytics');

    expect($raw)->not->toContain(TEST_GA4)->and($raw)->not->toBe('');
});

it('treats a cleared field as switching that vendor off', function () {
    // The opposite of the invoicing API key, and deliberately so: this value IS
    // shown in the form, so a blank field is a decision, not an omission.
    $tenant = measuringTenant(['ga4_measurement_id' => TEST_GA4, 'meta_pixel_id' => TEST_PIXEL]);
    $admin = analyticsAdmin($tenant);

    $this->actingAs($admin)->post(tenantHost('acme', '/settings/analytics'), [
        'ga4_measurement_id' => TEST_GA4,
        'meta_pixel_id' => '',
    ])->assertRedirect();

    expect(TenantAnalyticsSettings::fromArray($tenant->fresh()?->analytics)->metaPixelId)
        ->toBeNull();
});

it('rejects an id that is not one, instead of storing it', function () {
    $tenant = measuringTenant([]);
    $admin = analyticsAdmin($tenant);

    $this->actingAs($admin)
        ->from(tenantHost('acme', '/settings/analytics'))
        ->post(tenantHost('acme', '/settings/analytics'), [
            'ga4_measurement_id' => 'UA-12345-1',
            'meta_pixel_id' => 'pixel',
        ])
        ->assertSessionHasErrors(['ga4_measurement_id', 'meta_pixel_id']);

    expect($tenant->fresh()?->analytics)->toBeNull();
});

it('keeps the screen behind the feature flag', function () {
    $tenant = measuringTenant([]);
    app(SetTenantFeature::class)($tenant, Feature::Analytics, false);
    $admin = analyticsAdmin($tenant);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/analytics'))
        ->assertForbidden();
});

it('does not let a staff member without settings.edit near it', function () {
    $tenant = measuringTenant([]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user->assignRole(Role::Employee->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/settings/analytics'))
        ->assertForbidden();
});

// --- The conversion, and only once ---

/** @return array{0: Tenant, 1: Booking} */
function measuredBookingFixture(BookingStatus $status = BookingStatus::Confirmed): array
{
    $tenant = measuringTenant([
        'ga4_measurement_id' => TEST_GA4,
        'meta_pixel_id' => TEST_PIXEL,
    ]);

    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
    ]);

    $booking = Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'customer_id' => null,
        'guest_name' => 'Teszt Vendég',
        'guest_email' => 'vendeg@example.test',
        'status' => $status,
        'price_minor' => 1_250_000,
        'currency' => 'HUF',
        'starts_at' => Carbon::now()->addDays(3),
        'ends_at' => Carbon::now()->addDays(3)->addHour(),
    ]);

    app(TenantManager::class)->forget();

    return [$tenant, $booking];
}

it('reports a confirmed booking as a conversion exactly once', function () {
    // /booked/{code} is a PERMANENT link: the guest keeps it, the payment gateway
    // returns to it, an admin may open it. A page that fired on every render
    // would inflate the tenant's ad reporting — the kind of wrong number that
    // gets believed, because nothing looks broken.
    [, $booking] = measuredBookingFixture();

    $consented = withConsent(['analytics' => true, 'marketing' => true]);

    $consented->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('measurable', true));

    $consented->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('measurable', false));
});

it('does not report a booking that is not a sale yet', function () {
    // Awaiting payment is not revenue. Reporting it would put money into the
    // tenant's ad platform for a slot that may lapse unpaid an hour later.
    [, $booking] = measuredBookingFixture(BookingStatus::PendingPayment);

    withConsent(['analytics' => true, 'marketing' => true])
        ->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('measurable', false));
});

it('does not report a conversion to a visitor who consented to nothing', function () {
    [, $booking] = measuredBookingFixture();

    $this->get(tenantHost('acme', '/booked/'.$booking->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('measurable', false));
});
