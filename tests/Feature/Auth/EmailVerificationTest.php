<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function verificationUser(Role $role): User
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->unverified()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

/** The link exactly as the verification mail carries it — not one rebuilt here. */
function verificationMailLink(User $user): string
{
    Notification::fake();
    $user->sendEmailVerificationNotification();

    $link = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $mail) use ($user, &$link) {
        $link = $mail->toMail($user)->actionUrl;

        return true;
    });

    return (string) $link;
}

it('verifies the email via a signed verification link', function () {
    $user = verificationUser(Role::TenantAdmin);

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addHour(),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($url)->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

// SLO-242: Fortify's default sent everyone to `/dashboard` on the link's own
// host — the central domain, where that page does not exist.
it('sends a verified staff user to their subdomain dashboard, not a central 404', function () {
    $user = verificationUser(Role::TenantAdmin);

    $this->actingAs($user)
        ->get(verificationMailLink($user))
        ->assertRedirect(tenantHost('acme', '/dashboard?verified=1'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('sends a verified customer to the members area', function () {
    $user = verificationUser(Role::Customer);

    $this->actingAs($user)
        ->get(verificationMailLink($user))
        ->assertRedirect(tenantHost('acme', '/my/bookings?verified=1'));
});

it('sends an already verified user home when the link is followed again', function () {
    $user = verificationUser(Role::TenantAdmin);
    $link = verificationMailLink($user);
    $this->actingAs($user)->get($link);

    $this->actingAs($user->fresh())
        ->get($link)
        ->assertRedirect(tenantHost('acme', '/dashboard?verified=1'));
});

it('renders the verification notice for an unverified user', function () {
    $user = User::factory()->unverified()->create(['tenant_id' => null]);

    $this->actingAs($user)
        ->get('http://'.config('tenancy.central_domain').'/email/verify')
        ->assertOk();
});
