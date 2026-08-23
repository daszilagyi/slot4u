<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Two-factor authentication (SLO-149, docs/01 OWASP A07)
|--------------------------------------------------------------------------
|
| Fortify has supported this since the app was scaffolded; it was simply never
| switched on. The accounts it matters for are not incidental: a tenant-admin
| holds a company's entire customer list, and the superadmin sees every tenant
| and can impersonate into any of them.
|
| Two properties get the most attention here, because both fail silently:
|
|   - the secret and the recovery codes must never reach the browser as data
|     (a second factor in an Inertia prop is a second factor the page's reader
|     already has), and
|   - the superadmin must not be able to switch it off — including by issuing
|     the DELETE by hand, which is precisely what the person the requirement
|     guards against would do.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * The code the user's authenticator shows, `$stepsAhead` 30-second windows from
 * now.
 *
 * ⚠️ The offset is not padding. Fortify remembers every code it accepts
 * (`verifyKeyNewer` against a cache entry) and refuses to take the same one
 * twice — replay protection, and correct. So a test that confirms setup with one
 * code and then signs in with the *same* code is testing the replay guard, not
 * the login. A real user waits and reads the next one; these tests do the same.
 */
function totpCode(User $user, int $stepsAhead = 0): string
{
    $secret = decrypt((string) $user->two_factor_secret);

    return app(Google2FA::class)->oathTotp($secret, intdiv(time(), 30) + $stepsAhead);
}

/** A tenant admin on `acme`, signed in and past the password wall. */
function twoFactorAdmin(): User
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    return $user;
}

/** Mark the password as freshly confirmed, as the wall would. */
function passwordConfirmed(): void
{
    session()->put('auth.password_confirmed_at', time());
}

// --- The feature is actually on ---

it('has two-factor switched on, with confirmation and a password wall', function () {
    // The config said "out of MVP scope" for four milestones. Pinned so it
    // cannot quietly go back: without `confirm` a half-finished setup locks the
    // account out, and without `confirmPassword` the session 2FA defends
    // against could simply turn it off.
    expect(Features::enabled(Features::twoFactorAuthentication()))->toBeTrue()
        ->and(Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'))->toBeTrue()
        ->and(Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'))->toBeTrue();
});

// --- Setting it up ---

it('does not arm the second factor until a generated code is typed back', function () {
    // The state between scanning and confirming is real, not theoretical: it is
    // what a closed tab leaves behind. Treating it as "on" would lock the
    // account out of its own second factor at the next login.
    $user = twoFactorAdmin();
    passwordConfirmed();

    $this->actingAs($user)->post('/user/two-factor-authentication')->assertRedirect();

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($user)
        ->post('/user/confirmed-two-factor-authentication', ['code' => totpCode($user)])
        ->assertRedirect();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('will not accept the same code twice', function () {
    // Fortify's replay protection, pinned because it is a security property that
    // nothing else in the suite would notice losing: a code read over somebody's
    // shoulder is useless the moment it has been used once.
    $user = twoFactorAdmin();
    passwordConfirmed();
    $this->actingAs($user)->post('/user/two-factor-authentication');
    $user->refresh();

    $code = totpCode($user);
    $this->actingAs($user)->post('/user/confirmed-two-factor-authentication', ['code' => $code]);

    auth()->logout();
    session()->flush();

    $this->post(tenantHost('acme', '/login'), ['email' => $user->email, 'password' => 'password']);
    $this->post(tenantHost('acme', '/two-factor-challenge'), ['code' => $code]);

    $this->assertGuest();
});

it('refuses a wrong confirmation code', function () {
    $user = twoFactorAdmin();
    passwordConfirmed();

    $this->actingAs($user)->post('/user/two-factor-authentication');
    $this->actingAs($user)
        // Fortify puts this one in a NAMED bag, so the plain assertion would
        // pass on an empty default bag and prove nothing.
        ->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertSessionHasErrors(['code'], null, 'confirmTwoFactorAuthentication');

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

// --- Signing in ---

it('stops at the second factor instead of signing the user straight in', function () {
    $user = twoFactorAdmin();
    passwordConfirmed();
    $this->actingAs($user)->post('/user/two-factor-authentication');
    $user->refresh();
    $this->actingAs($user)->post('/user/confirmed-two-factor-authentication', ['code' => totpCode($user)]);

    auth()->logout();
    session()->flush();

    $this->post(tenantHost('acme', '/login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/two-factor-challenge');

    $this->assertGuest();
});

it('signs in with a code from the authenticator', function () {
    $user = twoFactorAdmin();
    passwordConfirmed();
    $this->actingAs($user)->post('/user/two-factor-authentication');
    $user->refresh();
    $this->actingAs($user)->post('/user/confirmed-two-factor-authentication', ['code' => totpCode($user)]);
    $user->refresh();

    auth()->logout();
    session()->flush();

    $this->post(tenantHost('acme', '/login'), ['email' => $user->email, 'password' => 'password']);
    // The NEXT code, as a real user would read after waiting — see totpCode().
    $this->post(tenantHost('acme', '/two-factor-challenge'), ['code' => totpCode($user, 1)])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('signs in with a recovery code when the authenticator is gone', function () {
    // The half people only need on their worst day. If it does not work, the
    // mandatory second factor becomes a lockout.
    $user = twoFactorAdmin();
    passwordConfirmed();
    $this->actingAs($user)->post('/user/two-factor-authentication');
    $user->refresh();
    $this->actingAs($user)->post('/user/confirmed-two-factor-authentication', ['code' => totpCode($user)]);
    $user->refresh();

    $code = $user->recoveryCodes()[0];

    auth()->logout();
    session()->flush();

    $this->post(tenantHost('acme', '/login'), ['email' => $user->email, 'password' => 'password']);
    $this->post(tenantHost('acme', '/two-factor-challenge'), ['recovery_code' => $code])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);

    // Single use: the code it just consumed is gone from the list.
    expect($user->fresh()->recoveryCodes())->not->toContain($code);
});

// --- The secret never leaves the server as data ---

it('never puts the secret or the recovery codes into a serialised user', function () {
    // The security page renders a QR and prints the codes deliberately, behind a
    // password wall. What must never happen is the raw values riding along in
    // the `auth.user` prop that every single page carries.
    $user = twoFactorAdmin();
    passwordConfirmed();
    $this->actingAs($user)->post('/user/two-factor-authentication');
    $user->refresh();

    expect(array_keys($user->toArray()))
        ->not->toContain('two_factor_secret')
        ->not->toContain('two_factor_recovery_codes');
});

// --- The superadmin gate ---

it('sends a superadmin without a second factor to set one up', function () {
    $admin = superAdminWithoutTwoFactor();

    $this->actingAs($admin)
        ->get(superUrl('/'))
        ->assertRedirect('/security');
});

it('lets the superadmin through once the factor is confirmed', function () {
    $this->actingAs(superAdmin())
        ->get(superUrl('/'))
        ->assertOk();
});

it('leaves the security page reachable while the gate is closed', function () {
    // ⚠️ The loop this guards: a middleware that redirects to a page it also
    // guards leaves the person no way to satisfy the requirement.
    $admin = superAdminWithoutTwoFactor();
    passwordConfirmed();

    $this->actingAs($admin)->get(superUrl('/security'))->assertOk();
});

it('refuses to let a superadmin switch their own second factor off', function () {
    // Not merely a hidden button. Fortify's endpoint is a plain DELETE that
    // anyone holding the session can issue by hand — and "anyone holding the
    // session" is exactly who this requirement exists to stop.
    $admin = superAdmin();
    passwordConfirmed();

    $this->actingAs($admin)
        ->delete('/user/two-factor-authentication')
        ->assertForbidden();

    expect($admin->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('lets a tenant admin switch theirs off', function () {
    // Optional for a tenant-admin on purpose (docs/03): their blast radius is
    // their own customer list, and locking a paying customer out of their
    // booking system is a worse trade than the risk it removes.
    $user = twoFactorAdmin();
    passwordConfirmed();
    $this->actingAs($user)->post('/user/two-factor-authentication');
    $user->refresh();
    $this->actingAs($user)->post('/user/confirmed-two-factor-authentication', ['code' => totpCode($user)]);

    $this->actingAs($user)->delete('/user/two-factor-authentication')->assertRedirect();

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

// --- The way back ---

it('unlocks an account from the console, and records that it happened', function () {
    // Without this, a lost phone locks the platform's only administrator out of
    // every tenant — which is not a security posture, it is a single point of
    // failure with a password on it.
    $admin = superAdmin();

    $this->artisan('two-factor:reset', ['email' => $admin->email])
        ->expectsConfirmation("Remove two-factor authentication from {$admin->email}?", 'yes')
        ->assertSuccessful();

    expect($admin->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and($admin->fresh()->two_factor_secret)->toBeNull();

    // Recorded, because "who unlocked that account, and when?" is the question
    // somebody will eventually ask. A hand-written UPDATE answers nothing.
    expect(AuditLog::withoutGlobalScopes()->where('action', AuditAction::TwoFactorReset->value)->count())
        ->toBe(1);
});

it('does nothing to an account that has no second factor', function () {
    $user = twoFactorAdmin();

    $this->artisan('two-factor:reset', ['email' => $user->email])->assertSuccessful();

    expect(AuditLog::withoutGlobalScopes()->where('action', AuditAction::TwoFactorReset->value)->count())
        ->toBe(0);
});
