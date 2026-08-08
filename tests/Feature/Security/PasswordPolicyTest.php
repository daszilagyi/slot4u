<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\FakeUncompromisedVerifier;

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function pwCentral(string $path = '/'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

/**
 * @return array<string, string>
 */
function pwRegistration(string $password): array
{
    return [
        'company_name' => 'Acme Studio',
        'slug' => 'acme',
        'name' => 'Acme Admin',
        'email' => 'admin@acme.test',
        'password' => $password,
        'password_confirmation' => $password,
    ];
}

// --- Length ---

it('rejects a password shorter than the policy at registration', function () {
    // These accounts hold a tenant's whole customer base; Laravel's bare eight
    // characters is not the bar we want for them (SLO-145).
    $this->post(pwCentral('/register'), pwRegistration('short12345'))
        ->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'admin@acme.test')->exists())->toBeFalse();
});

it('accepts a password that meets the policy', function () {
    $this->post(pwCentral('/register'), pwRegistration('correct-horse-battery'))
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'admin@acme.test')->exists())->toBeTrue();
});

// --- Breach check ---

it('rejects a password known from a breach', function () {
    // The real check is a k-anonymity lookup against haveibeenpwned; the verifier
    // is faked so the suite never depends on the network.
    $this->app->instance(
        UncompromisedVerifier::class,
        new FakeUncompromisedVerifier(compromised: true),
    );

    $this->post(pwCentral('/register'), pwRegistration('correct-horse-battery'))
        ->assertSessionHasErrors('password');
});

it('applies the same policy to a members-area password change', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $customer = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => Hash::make('current-password-12'),
    ]);
    $customer->assignRole(Role::Customer->value);

    $this->actingAs($customer)
        ->from(tenantHost('acme', '/my/profile'))
        ->put(tenantHost('acme', '/my/password'), [
            'current_password' => 'current-password-12',
            'password' => 'short12345',
            'password_confirmation' => 'short12345',
        ])
        ->assertSessionHasErrors('password');
});

// --- Session fixation ---

it('gives the session a new id at login', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'admin@acme.test',
    ]);
    $user->assignRole(Role::TenantAdmin->value);

    // Touch a page first so a session exists to be fixated on.
    $this->get(tenantHost('acme', '/login'))->assertOk();
    $before = session()->getId();

    $this->post(tenantHost('acme', '/login'), [
        'email' => 'admin@acme.test',
        'password' => 'password',
    ])->assertRedirect();

    expect(session()->getId())->not->toBe($before);
    $this->assertAuthenticatedAs($user);
});
