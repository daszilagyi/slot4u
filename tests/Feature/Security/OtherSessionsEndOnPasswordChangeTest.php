<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| A password change ends every other session (SLO-99)
|--------------------------------------------------------------------------
|
| Before this, changing the password in the members area left every other
| signed-in device signed in — including one holding a stolen session cookie,
| which is the case a password change is usually made for. Each session now
| remembers the password hash it was opened under (AuthenticateSession in the
| web group) and ends itself when that hash stops matching.
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

function otherSessionsCustomer(): User
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => Hash::make('old-secret-123'),
    ]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

function otherSessionsChangePassword(mixed $test, User $user): mixed
{
    return $test->actingAs($user)
        ->from(tenantHost('acme', '/my/profile'))
        ->put(tenantHost('acme', '/my/password'), [
            'current_password' => 'old-secret-123',
            'password' => 'new-strong-456',
            'password_confirmation' => 'new-strong-456',
        ]);
}

it('signs out a session that was opened under the old password', function () {
    $me = otherSessionsCustomer();

    // Another device: a session that has been used since the old password.
    $this->actingAs($me)->get(tenantHost('acme', '/my/profile'))->assertOk();

    // The password changes somewhere else — the members area on a second
    // device, or a reset through the forgotten-password flow. Either way, all
    // the first device sees is a different hash on its next request.
    $me->forceFill(['password' => Hash::make('changed-elsewhere-789')])->save();

    $this->actingAs($me->fresh())
        ->get(tenantHost('acme', '/my/profile'))
        ->assertRedirectContains('/login');

    $this->assertGuest();
});

it('keeps the session that made the change signed in', function () {
    $me = otherSessionsCustomer();

    $this->actingAs($me)->get(tenantHost('acme', '/my/profile'))->assertOk();

    otherSessionsChangePassword($this, $me)->assertRedirect(tenantHost('acme', '/my/profile'));

    expect(Hash::check('new-strong-456', $me->fresh()->password))->toBeTrue();

    $this->actingAs($me->fresh())
        ->get(tenantHost('acme', '/my/profile'))
        ->assertOk();
});

it('ends a session still carrying the hash from before the change', function () {
    $me = otherSessionsCustomer();
    $oldHash = $me->password;

    otherSessionsChangePassword($this, $me)->assertRedirect(tenantHost('acme', '/my/profile'));

    // A second device: its session was opened under the old password, and
    // stores it the way the middleware does — as an HMAC of the hash.
    $this->flushSession();

    $this->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($oldHash)])
        ->actingAs($me->fresh())
        ->get(tenantHost('acme', '/my/profile'))
        ->assertRedirectContains('/login');

    $this->assertGuest();
});

it('re-issues this device\'s remember-me cookie with the new password hash', function () {
    // The remember-me cookie carries the password hash too. Without re-issuing
    // it, the device that changed the password would be signed out as well —
    // the first time its session expired and the cookie was presented.
    $me = otherSessionsCustomer();
    $me->forceFill(['remember_token' => 'remember-me-token'])->save();
    $recaller = Auth::guard('web')->getRecallerName();

    $oldMac = Auth::guard('web')->hashPasswordForCookie($me->password);

    $response = $this->withCookie($recaller, "{$me->id}|remember-me-token|{$oldMac}")
        ->actingAs($me)
        ->from(tenantHost('acme', '/my/profile'))
        ->put(tenantHost('acme', '/my/password'), [
            'current_password' => 'old-secret-123',
            'password' => 'new-strong-456',
            'password_confirmation' => 'new-strong-456',
        ]);

    $response->assertCookie($recaller);

    // The cookie carries an HMAC of the password hash, not the hash itself.
    $segments = explode('|', (string) $response->getCookie($recaller)?->getValue());

    expect(end($segments))->toBe(Auth::guard('web')->hashPasswordForCookie($me->fresh()->password));
});
