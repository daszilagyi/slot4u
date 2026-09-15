<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('renders the forgot-password page', function () {
    $this->get('http://'.config('tenancy.central_domain').'/forgot-password')
        ->assertOk();
});

it('sends a reset link to a known email', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'admin@acme.test']);

    $this->post('http://'.config('tenancy.central_domain').'/forgot-password', [
        'email' => 'admin@acme.test',
    ])->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('rejects a password that is too short', function () {
    $user = User::factory()->create(['email' => 'admin@acme.test']);
    $token = Password::broker()->createToken($user);
    $original = $user->password;

    $this->post('http://'.config('tenancy.central_domain').'/reset-password', [
        'token' => $token,
        'email' => 'admin@acme.test',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect($user->fresh()->password)->toBe($original);
});

it('resets the password with a valid token', function () {
    $user = User::factory()->create(['email' => 'admin@acme.test']);
    $token = Password::broker()->createToken($user);

    $this->post('http://'.config('tenancy.central_domain').'/reset-password', [
        'token' => $token,
        'email' => 'admin@acme.test',
        'password' => 'new-strong-password',
        'password_confirmation' => 'new-strong-password',
    ])->assertSessionHas('status');

    expect(Hash::check('new-strong-password', $user->fresh()->password))->toBeTrue();
});

it('marks an unverified address verified when its reset link is used (SLO-254)', function () {
    $user = User::factory()->unverified()->create(['email' => 'invited@acme.test']);
    $token = Password::broker()->createToken($user);

    $this->post('http://'.config('tenancy.central_domain').'/reset-password', [
        'token' => $token,
        'email' => 'invited@acme.test',
        'password' => 'new-strong-password',
        'password_confirmation' => 'new-strong-password',
    ])->assertSessionHas('status');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('keeps the original verification time of an already verified address', function () {
    $user = User::factory()->create(['email' => 'admin@acme.test', 'email_verified_at' => '2026-01-01 10:00:00']);
    $token = Password::broker()->createToken($user);

    $this->post('http://'.config('tenancy.central_domain').'/reset-password', [
        'token' => $token,
        'email' => 'admin@acme.test',
        'password' => 'new-strong-password',
        'password_confirmation' => 'new-strong-password',
    ]);

    expect($user->fresh()->email_verified_at->toDateTimeString())->toBe('2026-01-01 10:00:00');
});
