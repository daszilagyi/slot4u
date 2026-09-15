<?php

use App\Enums\Role;
use App\Models\Service;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Platform\SocialAccountChangedNotification;
use App\Notifications\Platform\SocialEmailConfirmationNotification;
use App\Services\SocialAuth\SocialAuthBroker;
use App\Services\SocialAuth\SocialLoginCompleter;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * Social sign-in, part two (SLO-252, docs/28): the booking wizard's buttons, the
 * profile's link / unlink and first password, and the address step for a
 * provider account without an e-mail.
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

function linkingTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);
    Service::factory()->forTenant($tenant)->create();

    return $tenant;
}

/** A customer signed in on the acme host, with or without a password. */
function linkingCustomer(Tenant $tenant, bool $withPassword = true): User
{
    $customer = socialStaff($tenant, Role::Customer->value, ['email' => 'anna@example.test']);

    if (! $withPassword) {
        $customer->forceFill(['password' => null])->save();
    }

    return $customer;
}

// --- Booking wizard ---------------------------------------------------------

it('offers the buttons on the booking page to a guest only', function () {
    $tenant = linkingTenant();

    $this->get(socialTenantUrl('acme', '/book'))
        ->assertInertia(fn (Assert $page) => $page->where('social_providers', ['google', 'facebook']));

    $this->actingAs(linkingCustomer($tenant))
        ->get(socialTenantUrl('acme', '/book'))
        ->assertInertia(fn (Assert $page) => $page->where('social_providers', []));
});

it('returns a guest to the exact booking step, signed in', function () {
    linkingTenant();
    socialFakeUser();

    $step = '/book?service=1&date=2026-10-01&start='.urlencode('2026-10-01T08:00:00Z');

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect?intent=booking&return='.urlencode($step)))
        ->assertRedirect($step);

    $this->assertAuthenticatedAs(User::query()->sole());
});

it('reports a refused booking sign-in on the booking step, not the login page', function () {
    linkingTenant();
    socialFakeUser('google', ['email_verified' => false]);

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect?intent=booking&return='.urlencode('/book?service=1')))
        ->assertRedirect('/book?service=1')
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.email_unverified')]);
});

it('prefills a guest booking when the address belongs to another business\'s customer', function () {
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    linkingTenant();
    socialStaff($other, Role::Customer->value, ['email' => 'anna@example.test']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect?intent=booking&return='.urlencode('/book?service=1')))
        ->assertRedirect('/book?service=1')
        ->assertSessionHas(SocialLoginCompleter::PREFILL_KEY, ['name' => 'Kiss Anna', 'email' => 'anna@example.test']);

    $this->assertGuest();

    $this->get(socialTenantUrl('acme', '/book?service=1'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('social_prefill', ['name' => 'Kiss Anna', 'email' => 'anna@example.test']));

    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('does not prefill a login attempt from the same refusal', function () {
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    linkingTenant();
    socialStaff($other, Role::Customer->value, ['email' => 'anna@example.test']);
    socialFakeUser();

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect'))
        ->assertRedirect('/login')
        ->assertSessionMissing(SocialLoginCompleter::PREFILL_KEY)
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.not_available')]);
});

// --- Linking from the profile -----------------------------------------------

it('links a provider to the signed-in customer, whatever address it has', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant);
    socialFakeUser('facebook', ['id' => 'fb-9', 'email' => null]);

    $this->actingAs($customer);
    session(['auth.password_confirmed_at' => time()]);

    socialRoundTrip(socialTenantUrl('acme', '/auth/facebook/redirect?intent=link&return=/my/profile'), 'facebook')
        ->assertRedirect('/my/profile')
        ->assertSessionHas('status', __('app.auth.social.linked', ['provider' => 'Facebook']));

    $account = SocialAccount::query()->withoutGlobalScopes()->sole();
    expect($account->user_id)->toBe($customer->id)
        ->and($account->provider_user_id)->toBe('fb-9');

    Notification::assertSentTo($customer, SocialAccountChangedNotification::class);
});

it('asks a password-holding customer to confirm the password before linking', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant);

    $this->actingAs($customer)
        ->get(socialTenantUrl('acme', '/auth/google/redirect?intent=link'))
        ->assertRedirect('/user/confirm-password');

    expect(session('url.intended'))->toBe(socialTenantUrl('acme', '/auth/google/redirect?intent=link'));
});

it('lets a passwordless customer link without a password prompt', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant, withPassword: false);
    SocialAccount::factory()->linkedTo($customer)->create(['provider_user_id' => 'g-existing']);
    socialFakeUser('facebook', ['id' => 'fb-2']);

    $this->actingAs($customer);

    socialRoundTrip(socialTenantUrl('acme', '/auth/facebook/redirect?intent=link'), 'facebook')
        ->assertSessionHasNoErrors();

    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('does not let staff link a provider (no page to see or remove it)', function () {
    $tenant = linkingTenant();
    $admin = socialStaff($tenant, Role::TenantAdmin->value);

    $this->actingAs($admin);
    session(['auth.password_confirmed_at' => time()]);

    $this->get(socialTenantUrl('acme', '/auth/google/redirect?intent=link'))->assertNotFound();
    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('does not start a link for a user of another tenant', function () {
    $other = linkingTenant('other');
    linkingTenant();
    $stranger = socialStaff($other, Role::Customer->value);

    $this->actingAs($stranger)
        ->get(socialTenantUrl('acme', '/auth/google/redirect?intent=link'))
        ->assertRedirect('/login');
});

it('sends a guest who asks to link to the login page', function () {
    linkingTenant();

    $this->get(socialTenantUrl('acme', '/auth/google/redirect?intent=link'))->assertRedirect('/login');
});

it('refuses to link an identity another account already signs in with', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant);
    $someoneElse = socialStaff($tenant, Role::Customer->value);
    SocialAccount::factory()->linkedTo($someoneElse)->create(['provider_user_id' => 'g-1001']);
    socialFakeUser();

    $this->actingAs($customer);
    session(['auth.password_confirmed_at' => time()]);

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect?intent=link'))
        ->assertRedirect('/my/profile')
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.identity_taken')]);

    expect(SocialAccount::query()->withoutGlobalScopes()->sole()->user_id)->toBe($someoneElse->id);
});

it('refuses a second identity at a provider already linked', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant);
    SocialAccount::factory()->linkedTo($customer)->create(['provider_user_id' => 'g-old']);
    socialFakeUser('google', ['id' => 'g-new']);

    $this->actingAs($customer);
    session(['auth.password_confirmed_at' => time()]);

    socialRoundTrip(socialTenantUrl('acme', '/auth/google/redirect?intent=link'))
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.provider_already_linked')]);

    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('links nothing when a different account completes the link', function () {
    $tenant = linkingTenant();
    $starter = linkingCustomer($tenant);
    $other = socialStaff($tenant, Role::Customer->value);
    socialFakeUser();

    $this->actingAs($starter);
    session(['auth.password_confirmed_at' => time()]);
    $consume = socialStartAndCallback(socialTenantUrl('acme', '/auth/google/redirect?intent=link'));

    // Somebody else is signed in on this browser by the time it comes back.
    auth()->guard('web')->setUser($other);

    $this->get($consume)->assertSessionHasErrors(['social' => __('app.auth.social.errors.expired')]);
    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('lists the linked accounts and the providers on the profile', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant, withPassword: false);
    SocialAccount::factory()->linkedTo($customer)->create();

    $this->actingAs($customer)
        ->get(socialTenantUrl('acme', '/my/profile'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.has_password', false)
            ->has('linked_accounts', 1)
            ->where('linked_accounts.0.provider', 'google')
            ->where('social_providers', ['google', 'facebook']));
});

// --- Unlinking and the first password ---------------------------------------

it('unlinks a provider when a password remains', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant);
    $account = SocialAccount::factory()->linkedTo($customer)->create();

    $this->actingAs($customer)
        ->delete(socialTenantUrl('acme', "/my/social-accounts/{$account->id}"))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(0);
    Notification::assertSentTo($customer, SocialAccountChangedNotification::class);
});

it('unlinks a provider when another provider remains', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant, withPassword: false);
    $google = SocialAccount::factory()->linkedTo($customer)->create();
    SocialAccount::factory()->linkedTo($customer)->facebook()->create();

    $this->actingAs($customer)
        ->delete(socialTenantUrl('acme', "/my/social-accounts/{$google->id}"))
        ->assertSessionHasNoErrors();

    expect(SocialAccount::query()->withoutGlobalScopes()->pluck('provider')->map->value->all())->toBe(['facebook']);
});

it('refuses to unlink the last way in', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant, withPassword: false);
    $account = SocialAccount::factory()->linkedTo($customer)->create();

    $this->actingAs($customer)
        ->delete(socialTenantUrl('acme', "/my/social-accounts/{$account->id}"))
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.last_sign_in_method')]);

    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('404s unlinking another customer\'s account, in this tenant or another', function () {
    $tenant = linkingTenant();
    $other = linkingTenant('other');
    $me = linkingCustomer($tenant);
    $neighbour = SocialAccount::factory()->linkedTo(socialStaff($tenant, Role::Customer->value))->create();
    $foreign = SocialAccount::factory()->linkedTo(socialStaff($other, Role::Customer->value))->create();

    foreach ([$neighbour, $foreign] as $account) {
        $this->actingAs($me)
            ->delete(socialTenantUrl('acme', "/my/social-accounts/{$account->id}"))
            ->assertNotFound();
    }

    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('mails a passwordless account a set-password link instead of setting one on the spot', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant, withPassword: false);

    $this->actingAs($customer)
        ->put(socialTenantUrl('acme', '/my/password'), [
            'password' => 'Uj-Jelszo-2026!',
            'password_confirmation' => 'Uj-Jelszo-2026!',
        ])
        ->assertSessionHasErrors('current_password');

    expect($customer->fresh()->hasPassword())->toBeFalse();

    $this->actingAs($customer)
        ->post(socialTenantUrl('acme', '/my/password/link'))
        ->assertRedirect()
        ->assertSessionHas('status', __('app.tenant.my.profile.password_link_sent'));

    Notification::assertSentTo($customer, ResetPassword::class);
});

it('404s the set-password link for an account that has a password', function () {
    $tenant = linkingTenant();

    $this->actingAs(linkingCustomer($tenant))
        ->post(socialTenantUrl('acme', '/my/password/link'))
        ->assertNotFound();
});

it('still asks an account with a password for the current one', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant);

    $this->actingAs($customer)
        ->put(socialTenantUrl('acme', '/my/password'), [
            'password' => 'Uj-Jelszo-2026!',
            'password_confirmation' => 'Uj-Jelszo-2026!',
        ])
        ->assertSessionHasErrors('current_password');
});

// --- A provider account without an address ----------------------------------

/** Run a Facebook flow with no address up to the address page. */
function linkingNoEmailFlow(string $intentQuery = ''): void
{
    socialFakeUser('facebook', ['id' => 'fb-phone', 'email' => null, 'name' => 'Kiss Anna']);

    socialRoundTrip(socialTenantUrl('acme', '/auth/facebook/redirect'.$intentQuery), 'facebook')
        ->assertRedirect('/auth/social/email');
}

/** Submit the address and return the confirmation URL from the mail. */
function linkingSubmitAddress(string $email): string
{
    test()->post(socialTenantUrl('acme', '/auth/social/email'), ['email' => $email])
        ->assertRedirect('/auth/social/email');

    $url = null;

    Notification::assertSentOnDemand(
        SocialEmailConfirmationNotification::class,
        function (SocialEmailConfirmationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url): bool {
            if (($notifiable->routes['mail'] ?? null) !== $email) {
                return false;
            }

            $url = $notification->toMail($notifiable)->actionUrl;

            return true;
        },
    );

    return (string) $url;
}

it('shows the address page for the pending attempt only', function () {
    linkingTenant();

    $this->get(socialTenantUrl('acme', '/auth/social/email'))->assertRedirect('/login');

    linkingNoEmailFlow();

    $this->get(socialTenantUrl('acme', '/auth/social/email'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/SocialEmail')
            ->where('provider', 'Facebook')
            ->where('sentTo', null));
});

it('registers the customer once the address is confirmed in the same browser', function () {
    $tenant = linkingTenant();
    linkingNoEmailFlow();

    $url = linkingSubmitAddress('anna@example.test');

    // Nothing exists until the link is clicked.
    expect(User::query()->count())->toBe(0);

    expect($url)->toStartWith(socialTenantUrl('acme', '/auth/social/email/confirm?token='));

    $this->get($url)->assertRedirect('/my/bookings');

    $user = User::query()->sole();
    expect($user->email)->toBe('anna@example.test')
        ->and($user->tenant_id)->toBe($tenant->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(SocialAccount::query()->withoutGlobalScopes()->sole()->provider_user_id)->toBe('fb-phone');

    $this->assertAuthenticatedAs($user);
});

it('links the confirmed address to an existing account', function () {
    $tenant = linkingTenant();
    $customer = linkingCustomer($tenant);
    linkingNoEmailFlow();

    $this->get(linkingSubmitAddress('anna@example.test'))->assertRedirect('/my/bookings');

    $this->assertAuthenticatedAs($customer);
    expect(SocialAccount::query()->withoutGlobalScopes()->sole()->user_id)->toBe($customer->id);
});

it('confirms nothing when the link is opened in another browser (takeover guard)', function () {
    $tenant = linkingTenant();
    $victim = linkingCustomer($tenant);

    // The attacker's phone-only Facebook account, and the victim's address…
    linkingNoEmailFlow();
    $url = linkingSubmitAddress('anna@example.test');

    // …the victim clicks the genuine mail in their own browser.
    $this->flushSession();

    $this->get($url)
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['social' => __('app.auth.social.errors.email_link_invalid')]);

    $this->assertGuest();
    expect($victim->socialAccounts()->withoutGlobalScopes()->count())->toBe(0);
});

it('accepts a confirmation link once', function () {
    linkingTenant();
    linkingNoEmailFlow();
    $url = linkingSubmitAddress('anna@example.test');

    $this->get($url)->assertRedirect('/my/bookings');
    auth()->logout();

    $this->get($url)->assertSessionHasErrors(['social' => __('app.auth.social.errors.email_link_invalid')]);
});

it('refuses a confirmation link for an address replaced since', function () {
    linkingTenant();
    linkingNoEmailFlow();

    $first = linkingSubmitAddress('old@example.test');
    linkingSubmitAddress('new@example.test');

    $this->get($first)->assertSessionHasErrors(['social' => __('app.auth.social.errors.email_link_invalid')]);
    expect(User::query()->count())->toBe(0);
});

it('refuses an expired confirmation link', function () {
    linkingTenant();
    linkingNoEmailFlow();
    $url = linkingSubmitAddress('anna@example.test');

    $this->travel(SocialAuthBroker::EMAIL_CONFIRMATION_TTL_SECONDS + 1)->seconds();

    $this->get($url)->assertSessionHasErrors(['social' => __('app.auth.social.errors.email_link_invalid')]);
    expect(User::query()->count())->toBe(0);
});

it('validates the typed address', function () {
    linkingTenant();
    linkingNoEmailFlow();

    $this->post(socialTenantUrl('acme', '/auth/social/email'), ['email' => 'not-an-address'])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

it('continues a booking with the confirmed address back on the booking step', function () {
    linkingTenant();
    linkingNoEmailFlow('?intent=booking&return='.urlencode('/book?service=1'));

    $this->get(linkingSubmitAddress('anna@example.test'))->assertRedirect('/book?service=1');
    $this->assertAuthenticatedAs(User::query()->sole());
});

it('caps the confirmation mails one attempt may send', function () {
    linkingTenant();
    linkingNoEmailFlow();

    foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $email) {
        $this->post(socialTenantUrl('acme', '/auth/social/email'), ['email' => $email])->assertSessionHasNoErrors();
    }

    $this->post(socialTenantUrl('acme', '/auth/social/email'), ['email' => 'd@example.test'])
        ->assertSessionHasErrors(['email' => __('app.auth.social.errors.too_many_emails')]);

    Notification::assertSentOnDemandTimes(SocialEmailConfirmationNotification::class, 3);
});

it('caps the confirmation mails one address may receive, across attempts', function () {
    linkingTenant();

    foreach (range(1, 3) as $attempt) {
        $this->flushSession();
        linkingNoEmailFlow();
        $this->post(socialTenantUrl('acme', '/auth/social/email'), ['email' => 'victim@example.test'])->assertSessionHasNoErrors();
    }

    $this->flushSession();
    linkingNoEmailFlow();
    $this->post(socialTenantUrl('acme', '/auth/social/email'), ['email' => 'victim@example.test'])
        ->assertSessionHasErrors(['email' => __('app.auth.social.errors.too_many_emails')]);
});

it('does not burn the link when something without the session fetches it first (mail scanner)', function () {
    linkingTenant();
    linkingNoEmailFlow();
    $url = linkingSubmitAddress('anna@example.test');

    $pending = session(SocialLoginCompleter::PENDING_KEY);

    // The scanner: same link, no session.
    $this->flushSession();
    $this->get($url)->assertSessionHasErrors('social');

    // The person, in the browser that started it.
    $this->flushSession();
    session([SocialLoginCompleter::PENDING_KEY => $pending]);

    $this->get($url)->assertRedirect('/my/bookings');
    $this->assertAuthenticatedAs(User::query()->sole());
});

it('keeps the pending attempt to the tenant that started it', function () {
    linkingTenant();
    linkingTenant('other');
    linkingNoEmailFlow();
    $url = linkingSubmitAddress('anna@example.test');

    $this->get(socialTenantUrl('other', '/auth/social/email'))->assertRedirect('/login');
    $this->get(str_replace('//acme.', '//other.', $url))->assertSessionHasErrors('social');

    expect(User::query()->count())->toBe(0);
});
