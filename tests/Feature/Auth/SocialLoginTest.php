<?php

use App\Actions\Tenant\SetTenantFeature;
use App\Enums\Feature;
use App\Enums\Role;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\SocialAuth\SocialAuthBroker;
use App\Services\SocialAuth\SocialAuthUrls;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature as Pennant;
use Spatie\Permission\PermissionRegistrar;

/*
 * Social sign-in (SLO-251, docs/28): the flow starts on the host the person is
 * on, the provider calls back on the central domain, and a single-use token
 * signs them in back on the starting host. The provider is faked; everything
 * on our side of it — the flow record, the nonce, the token, the resolution
 * rules — runs for real.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    config([
        'services.google.client_id' => 'google-id',
        'services.google.client_secret' => 'google-secret',
        'services.facebook.client_id' => 'facebook-id',
        'services.facebook.client_secret' => 'facebook-secret',
    ]);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

// --- Members: registration and linking ------------------------------------

it('registers a customer on the tenant host when nobody has the address', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))
        ->assertRedirect('/my/bookings');

    $user = User::query()->where('email', 'anna@example.test')->sole();

    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->name)->toBe('Kiss Anna')
        ->and($user->hasPassword())->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->isStaff())->toBeFalse();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    expect($user->hasRole(Role::Customer->value))->toBeTrue();

    $account = SocialAccount::query()->withoutGlobalScopes()->sole();
    expect($account->user_id)->toBe($user->id)
        ->and($account->tenant_id)->toBe($tenant->id)
        ->and($account->provider)->toBe(SocialProvider::Google)
        ->and($account->provider_user_id)->toBe('g-1001')
        ->and($account->avatar_url)->toBe('https://example.test/anna.png');

    $this->assertAuthenticatedAs($user);
});

it('links a verified address to the existing customer and signs them in', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = socialStaff($tenant, Role::Customer->value, ['email' => 'anna@example.test']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))->assertRedirect('/my/bookings');

    expect(User::query()->count())->toBe(1)
        ->and($customer->socialAccounts()->withoutGlobalScopes()->count())->toBe(1)
        // A verified account keeps its password: nothing about it was in doubt.
        ->and(Hash::check('password', (string) $customer->fresh()->password))->toBeTrue();

    $this->assertAuthenticatedAs($customer);
});

it('gives the same address one account with a row per provider', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    socialFakeUser('google');
    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))->assertRedirect('/my/bookings');

    auth()->logout();
    $this->flushSession();

    socialFakeUser('facebook', ['id' => 'fb-77']);
    socialRoundTrip(socialTenantUrl('acme', '/auth/facebook/redirect'), 'facebook')->assertRedirect('/my/bookings');

    expect(User::query()->count())->toBe(1)
        ->and(SocialAccount::query()->withoutGlobalScopes()->pluck('provider')->map->value->sort()->values()->all())
        ->toBe(['facebook', 'google']);
});

it('signs a linked identity in even after the provider address changed', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = socialStaff($tenant, Role::Customer->value, ['email' => 'anna@example.test']);
    SocialAccount::factory()->linkedTo($customer)->create(['provider_user_id' => 'g-1001']);

    socialFakeUser('google', ['email' => 'anna.new@example.test']);

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))->assertRedirect('/my/bookings');

    $this->assertAuthenticatedAs($customer);
    expect(User::query()->where('email', 'anna.new@example.test')->exists())->toBeFalse()
        ->and(SocialAccount::query()->withoutGlobalScopes()->sole()->email)->toBe('anna.new@example.test');
});

// --- Staff and admins: never created, only admitted ------------------------

it('signs an existing tenant admin in by verified address', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = socialStaff($tenant, Role::TenantAdmin->value, ['email' => 'anna@example.test']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($admin);
});

it('signs staff in on the central login and sends them to their own subdomain', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $employee = socialStaff($tenant, Role::Employee->value, ['email' => 'anna@example.test']);
    socialFakeUser();

    socialRoundTrip(socialCentral('/auth/google/redirect'))
        ->assertRedirect(socialTenantUrl('acme', '/dashboard'));

    $this->assertAuthenticatedAs($employee);
});

it('never creates an account from the central domain', function () {
    socialFakeUser();

    socialRoundTrip(socialCentral('/auth/google/redirect'))
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.no_account')]);

    expect(User::query()->count())->toBe(0)
        ->and(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
    $this->assertGuest();
});

it('only ever creates a customer, whatever the start request asks for', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect?intent=login&role=tenant-admin&context=admin'))
        ->assertRedirect('/my/bookings');

    $user = User::query()->sole();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    expect($user->isStaff())->toBeFalse()
        ->and($user->getRoleNames()->all())->toBe([Role::Customer->value]);
});

it('refuses a super-admin', function () {
    User::factory()->create(['tenant_id' => null, 'email' => 'anna@example.test']);
    socialFakeUser();

    socialRoundTrip(socialCentral('/auth/google/redirect'))
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.not_available')]);

    $this->assertGuest();
    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('does not offer the buttons on the admin panel, and has no route there', function () {
    $this->get('http://'.config('tenancy.admin_subdomain').'.'.config('tenancy.central_domain').'/login')
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('socialProviders', []));

    $this->get('http://'.config('tenancy.admin_subdomain').'.'.config('tenancy.central_domain').'/auth/google/redirect')
        ->assertNotFound();
});

it('keeps a second factor in front of a provider sign-in', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = socialStaff($tenant, Role::TenantAdmin->value, [
        'email' => 'anna@example.test',
        'two_factor_secret' => encrypt('SECRET'),
        'two_factor_confirmed_at' => now(),
    ]);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))->assertRedirect('/two-factor-challenge');

    $this->assertGuest();
    expect(session('login.id'))->toBe($admin->id);
});

// --- Refusals ---------------------------------------------------------------

it('does not link on an address the provider has not verified', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    socialStaff($tenant, Role::Customer->value, ['email' => 'anna@example.test']);
    socialFakeUser('google', ['email_verified' => false]);

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.email_unverified')]);

    $this->assertGuest();
    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('asks for an address instead of signing in a Facebook account without one', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser('facebook', ['id' => 'fb-1', 'email' => null]);

    socialRoundTrip(socialTenantUrl('acme', '/auth/facebook/redirect'), 'facebook')
        ->assertRedirect('/auth/social/email');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses another tenant\'s user with the neutral message', function () {
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialStaff($other, Role::Customer->value, ['email' => 'anna@example.test']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.not_available')]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(1)
        ->and(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('revokes the password of a never-verified account it links to (pre-hijack)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $squatted = socialStaff($tenant, Role::Customer->value, [
        'email' => 'anna@example.test',
        'email_verified_at' => null,
    ]);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))->assertRedirect('/my/bookings');

    $squatted->refresh();
    expect($squatted->hasPassword())->toBeFalse()
        ->and($squatted->email_verified_at)->not->toBeNull();
});

it('revokes the password an admin knows when the real owner of a changed address signs in (SLO-254)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = socialStaff($tenant, Role::TenantAdmin->value);
    // A verified customer whose password the admin set or knows…
    $account = socialStaff($tenant, Role::Customer->value, ['email' => 'someone@example.test']);

    // …gets the address of a person who never had anything to do with it.
    $this->actingAs($admin)
        ->put(socialTenantUrl('acme', "/customers/{$account->id}"), ['name' => 'Kiss Anna', 'email' => 'anna@example.test'])
        ->assertSessionHasNoErrors();
    auth()->logout();
    $this->flushSession();

    socialFakeUser();
    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))->assertRedirect('/my/bookings');

    $account->refresh();
    expect($account->hasPassword())->toBeFalse()
        ->and($account->email_verified_at)->not->toBeNull();
});

it('reports a cancelled consent screen back on the starting host', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    $this->get(socialTenantUrl('acme', '/auth/google/redirect'));
    $state = (string) array_key_last((array) session('social_login.nonces'));

    $consume = $this->get(socialCentral('/auth/google/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied'])))
        ->headers->get('Location');

    expect($consume)->toStartWith(socialTenantUrl('acme', '/auth/social/consume?token='));

    $this->get($consume)->assertSessionHasErrors(['social' => __('app.auth.social.errors.cancelled')]);
    $this->assertGuest();
});

it('rejects a callback with an unknown or replayed state', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    $this->get(socialTenantUrl('acme', '/auth/google/redirect'));
    $state = (string) array_key_last((array) session('social_login.nonces'));
    $callback = socialCentral('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']));

    $this->get($callback)->assertRedirect();

    $this->get($callback)
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.expired')]);

    $this->get(socialCentral('/auth/google/callback?state=nonsense&code=c'))
        ->assertSessionHasErrors('social');
});

it('404s a provider without credentials', function () {
    config(['services.facebook.client_id' => null]);
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(socialTenantUrl('acme', '/auth/facebook/redirect'))->assertNotFound();
    $this->get(socialCentral('/auth/facebook/callback'))->assertNotFound();
    $this->get(socialTenantUrl('acme', '/login'))
        ->assertInertia(fn (Assert $page) => $page->where('socialProviders', ['google']));
});

it('does not start a flow on a suspended tenant', function () {
    Tenant::factory()->suspended()->create(['slug' => 'acme']);

    $this->get(socialTenantUrl('acme', '/auth/google/redirect'))->assertStatus(503);
});

// --- The handoff token ------------------------------------------------------

it('redeems a handoff token only once', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    $consume = socialStartAndCallback(socialTenantUrl('acme', '/auth/google/redirect'));

    $this->get($consume)->assertRedirect('/my/bookings');
    auth()->logout();

    $this->get($consume)
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.expired')]);
    $this->assertGuest();
});

it('refuses an expired handoff token', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    $consume = socialStartAndCallback(socialTenantUrl('acme', '/auth/google/redirect'));

    $this->travel(SocialAuthBroker::HANDOFF_TTL_SECONDS + 1)->seconds();

    $this->get($consume)->assertSessionHasErrors(['social' => __('app.auth.social.errors.expired')]);
    $this->assertGuest();
});

it('refuses a handoff token in a browser that did not start the flow (login CSRF)', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    // The attacker runs the flow with their own provider account…
    $consume = socialStartAndCallback(socialTenantUrl('acme', '/auth/google/redirect'));

    // …and the victim opens the link in their own session.
    $this->flushSession();

    $this->get($consume)->assertSessionHasErrors(['social' => __('app.auth.social.errors.expired')]);
    $this->assertGuest();
});

it('creates and links nothing for a callback that reaches a browser which did not start the flow', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    // The attacker starts a flow and copies the provider URL (state included)…
    $this->get(socialTenantUrl('acme', '/auth/google/redirect'));
    $state = (string) array_key_last((array) session('social_login.nonces'));

    // …the victim opens it in their own browser and signs in at the provider.
    $this->flushSession();
    $consume = $this->get(socialCentral('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c'])))
        ->headers->get('Location');

    // The callback decided nothing: no account exists for the victim's address.
    expect(User::query()->count())->toBe(0)
        ->and(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);

    // And the victim's browser cannot redeem it either.
    $this->get($consume)->assertSessionHasErrors(['social' => __('app.auth.social.errors.expired')]);
    expect(User::query()->count())->toBe(0);
});

it('refuses a token started on one tenant and opened on another', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    Tenant::factory()->active()->create(['slug' => 'other']);
    socialFakeUser();

    $consume = socialStartAndCallback(socialTenantUrl('acme', '/auth/google/redirect'));
    $moved = str_replace('//acme.', '//other.', $consume);

    // `expired`, not `not_available`: the flow's own tenant binding refuses it,
    // before the account re-check (which would also refuse) is ever reached.
    $this->get($moved)->assertSessionHasErrors(['social' => __('app.auth.social.errors.expired')]);
    $this->assertGuest();
});

it('returns to a relative path from the start request', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect?return='.urlencode('/book?service=3&date=2026-09-20')))
        ->assertRedirect('/book?service=3&date=2026-09-20');
});

it('ignores a return target that would leave the host', function (string $target) {
    Tenant::factory()->active()->create(['slug' => 'acme']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect?return='.urlencode($target)))
        ->assertRedirect('/my/bookings');
})->with([
    'absolute' => 'https://evil.test/phish',
    'protocol-relative' => '//evil.test/phish',
    'backslash' => '/\\evil.test',
    'scheme only' => 'javascript:alert(1)',
]);

it('accepts only same-host paths as return targets', function (mixed $value, ?string $expected) {
    expect(SocialAuthUrls::safeReturnPath($value))->toBe($expected);
})->with([
    ['/my/bookings', '/my/bookings'],
    ['/book?service=1', '/book?service=1'],
    ['https://evil.test', null],
    ['//evil.test', null],
    ['/\\evil.test', null],
    ["/book\r\nSet-Cookie: x", null],
    ['book', null],
    ['/auth/google/redirect', null],
    ['', null],
    [null, null],
    [['/x'], null],
]);

it('completes the flow on a tenant\'s own domain', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(SetTenantFeature::class)($tenant, Feature::CustomDomain, true);
    Pennant::flushCache();
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);
    socialFakeUser();

    $consume = socialStartAndCallback('http://foglalas.acme.hu/auth/google/redirect');

    expect($consume)->toStartWith('http://foglalas.acme.hu/auth/social/consume?token=');

    $this->get($consume)->assertRedirect('/my/bookings');
    $this->assertAuthenticatedAs(User::query()->sole());
});

// --- Password login, isolation, privacy -------------------------------------

it('tells a passwordless account to use its provider on the password form', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = socialStaff($tenant, Role::Customer->value, ['email' => 'anna@example.test']);
    $customer->forceFill(['password' => null])->save();

    $this->post(socialTenantUrl('acme', '/login'), ['email' => 'anna@example.test', 'password' => 'anything'])
        ->assertSessionHasErrors(['email' => __('auth.social_only')]);

    $this->assertGuest();
});

it('does not reveal a passwordless account of another tenant on the password form', function () {
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    Tenant::factory()->active()->create(['slug' => 'acme']);
    $customer = socialStaff($other, Role::Customer->value, ['email' => 'anna@example.test']);
    $customer->forceFill(['password' => null])->save();

    foreach ([socialTenantUrl('acme', '/login'), socialCentral('/login')] as $url) {
        $this->post($url, ['email' => 'anna@example.test', 'password' => 'anything'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    $this->assertGuest();
});

it('still answers a wrong password with the generic failure', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    socialStaff($tenant, Role::Customer->value, ['email' => 'anna@example.test']);

    $this->post(socialTenantUrl('acme', '/login'), ['email' => 'anna@example.test', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->post(socialTenantUrl('acme', '/login'), ['email' => 'nobody@example.test', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
});

it('isolates social accounts per tenant', function () {
    $a = Tenant::factory()->active()->create(['slug' => 'aaa']);
    $b = Tenant::factory()->active()->create(['slug' => 'bbb']);
    $userA = socialStaff($a, Role::Customer->value);
    $userB = socialStaff($b, Role::Customer->value);
    SocialAccount::factory()->linkedTo($userA)->create();
    $accountB = SocialAccount::factory()->linkedTo($userB)->create();

    app(TenantManager::class)->set($a);

    expect(SocialAccount::query()->count())->toBe(1)
        ->and(SocialAccount::query()->find($accountB->id))->toBeNull();
});

it('offers the buttons on the tenant login and registration pages', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->get(socialTenantUrl('acme', '/login'))
        ->assertInertia(fn (Assert $page) => $page->where('socialProviders', ['google', 'facebook']));

    $this->get(socialTenantUrl('acme', '/register'))
        ->assertInertia(fn (Assert $page) => $page->component('Auth/RegisterCustomer')->where('socialProviders', ['google', 'facebook']));
});
