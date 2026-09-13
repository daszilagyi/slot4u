<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
