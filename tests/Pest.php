<?php

use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Services\Notification\MessageTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Fake notifications by default so the suite never renders/sends real mail. Every
// notification-asserting test already relied on this locally; making it the default
// keeps booking-heavy tests fast now that a confirmed booking emails the customer
// (SLO-108). Delivery-status logging is covered by driving the listener directly.
pest()->beforeEach(fn () => Notification::fake())->in('Feature');

// ⚠️ Server rendering OFF for the whole suite, here rather than in phpunit.xml
// — because the setting there does not take (SLO-222). `phpunit.xml` has said
// `INERTIA_SSR_ENABLED=false force="true"` for months, and `.env`'s `true` wins
// anyway: `config('inertia.ssr.enabled')` reads TRUE inside a test.
//
// The consequence was not a failure, which is why nobody saw it. Whenever
// `public/hot` happened to exist, Inertia sent every full-page render to the
// Vite dev server, got a refused connection and fell back in microseconds — the
// suite ran in 17 minutes. Whenever the file happened to be missing, the same
// renders went to the docker `ssr` service, which answers a health check in
// ~4 seconds, and the suite ran past 45 minutes and was killed. The suite's
// runtime depended on whether a file existed.
//
// A test that wants a real renderer turns it back on in its own beforeEach,
// which runs after this one.
pest()->beforeEach(fn () => config()->set('inertia.ssr.enabled', false))->in('Feature');

// ⚠️ Fake the disks the application writes tenant files to (SLO-227). The queue
// is synchronous in tests, so every test that settles a payment runs the real
// IssueInvoice job and writes a real PDF — and before this, onto the real dev
// disk: 150 000 files, 604 MB, 10–46 thousand more every day, and a Vite dev
// server burning 1.3 cores watching them arrive (SLO-225). A faked disk
// lives under storage/framework/testing, gets its own root per parallel worker,
// and is emptied at the start of every test. A test that fakes a disk again
// itself simply gets a fresh one.
pest()->beforeEach(function () {
    Storage::fake((string) config('invoicing.disk'));
    Storage::fake('public');
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** Base URL of the superadmin panel (admin.{central}). */
function superUrl(string $path = '/'): string
{
    return 'http://'.config('tenancy.admin_subdomain').'.'.config('tenancy.central_domain').$path;
}

/** Base URL of a tenant's subdomain. */
function tenantHost(string $slug, string $path = '/'): string
{
    return 'http://'.$slug.'.'.config('tenancy.central_domain').$path;
}

/**
 * A platform super-admin (no tenant), with its second factor already in place.
 *
 * ⚠️ The 2FA stamp is not test convenience. Since SLO-149 the superadmin panel
 * is gated on it, so a superadmin WITHOUT a confirmed second factor is a state
 * production refuses to serve — a fixture that created one would be testing a
 * user who cannot exist. The tests that exercise the gate itself build their own
 * (see `superAdminWithoutTwoFactor`).
 */
function superAdmin(): User
{
    return User::factory()->create([
        'tenant_id' => null,
        'two_factor_confirmed_at' => now(),
    ]);
}

/** A super-admin who has not set up 2FA yet — for testing the gate. */
function superAdminWithoutTwoFactor(): User
{
    return User::factory()->create(['tenant_id' => null]);
}

/**
 * Whether an Inertia SSR renderer is listening at the configured URL. The smoke
 * test needs a live node process (the `ssr` docker service, or
 * `node bootstrap/ssr/ssr.js`); without one it skips rather than failing, so the
 * normal suite stays green everywhere while CI runs it against a started server.
 */
function ssrRendererReachable(): bool
{
    $parts = parse_url((string) config('inertia.ssr.url'));
    $host = $parts['host'] ?? '127.0.0.1';
    $port = $parts['port'] ?? 13714;

    $conn = @fsockopen($host, $port, $errno, $errstr, 1);

    if ($conn === false) {
        return false;
    }

    fclose($conn);

    return true;
}

/**
 * A demo persona's own mail wording has to render as mail, not as a template
 * (SLO-247): the GlamZone and garage overrides were written with `{{customer_name}}`
 * while the renderer substitutes `:name`, so the showcase mail printed the braces
 * and greeted twice. Checks every override the tenant has, and renders the
 * confirmation for one of its real bookings.
 */
function expectDemoMailTemplatesToRender(Tenant $tenant): void
{
    $catalog = app(MessageTemplateCatalog::class);
    $templates = MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->get();

    expect($templates)->not->toBeEmpty();

    foreach ($templates as $template) {
        $text = $template->subject."\n".$template->body;
        preg_match_all('/:([a-z_]+)/', $text, $matches);

        expect($text)->not->toContain('{{')
            ->and(array_values(array_diff($matches[1], $catalog->variables($template->key))))
            ->toBe([], "{$template->key->value} uses a variable its notification does not pass")
            // The template path writes the greeting itself.
            ->and($template->body)->not->toMatch('/^\s*(Szia|Kedves)\b/u');
    }

    if ($templates->doesntContain('key', NotificationType::BookingConfirmed)) {
        return;
    }

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())
        ->whereNotNull('service_id')->whereNotNull('starts_at')->firstOrFail();
    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail((object) ['name' => 'Teszt Elek']);
    $rendered = $mail->subject."\n".$mail->greeting."\n".implode("\n", [...$mail->introLines, ...$mail->outroLines]);

    expect($rendered)->not->toContain('{{')
        ->not->toMatch('/(?<![\\w\/]):[a-z_]+/')
        ->toContain($booking->code)
        ->and($mail->greeting)->toBe('Szia Teszt Elek!')
        // Greeted once: by the template path, not again by the body.
        ->and(implode("\n", $mail->introLines))->not->toContain('Teszt Elek');
}

/*
|--------------------------------------------------------------------------
| Social sign-in (SLO-251, SLO-252)
|--------------------------------------------------------------------------
|
| Shared by the sign-in and the linking/booking test files. The provider is
| faked (Socialite::fake); the flow, the nonce, the token and the resolution
| run for real.
|
*/

function socialCentral(string $path = '/'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

function socialTenantUrl(string $slug, string $path = '/'): string
{
    return 'http://'.$slug.'.'.config('tenancy.central_domain').$path;
}

/** @param  array<string, mixed>  $attributes */
function socialFakeUser(string $provider = 'google', array $attributes = []): void
{
    Socialite::fake($provider, SocialiteUser::fake(array_merge([
        'id' => 'g-1001',
        'name' => 'Kiss Anna',
        'email' => 'anna@example.test',
        'email_verified' => true,
        'avatar' => 'https://example.test/anna.png',
    ], $attributes)));
}

function socialStaff(Tenant $tenant, string $role, array $attributes = []): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id, ...$attributes]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

/**
 * Click the button on `$startUrl` and let the provider call back. Returns the
 * consume URL the callback redirected to (on the starting host, with the token).
 */
function socialStartAndCallback(string $startUrl, string $provider = 'google'): string
{
    $test = test();

    $test->get($startUrl)->assertRedirect("https://socialite.fake/{$provider}/authorize");

    $nonces = (array) session('social_login.nonces');
    $state = (string) array_key_last($nonces);

    $response = $test->get(socialCentral("/auth/{$provider}/callback?".http_build_query(['state' => $state, 'code' => 'fake-code'])));
    $response->assertRedirect();

    return (string) $response->headers->get('Location');
}

/** The whole round trip; returns the consume response. */
function socialRoundTrip(string $startUrl, string $provider = 'google')
{
    return test()->get(socialStartAndCallback($startUrl, $provider));
}
