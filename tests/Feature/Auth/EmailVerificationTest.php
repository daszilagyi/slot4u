<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\URL;

it('verifies the email via a signed verification link', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->unverified()->create(['tenant_id' => $tenant->id]);

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addHour(),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($url)->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('renders the verification notice for an unverified user', function () {
    $user = User::factory()->unverified()->create(['tenant_id' => null]);

    $this->actingAs($user)
        ->get('http://'.config('tenancy.central_domain').'/email/verify')
        ->assertOk();
});
