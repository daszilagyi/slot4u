<?php

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function central(string $path = '/'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Acme Studio',
        'slug' => 'acme',
        'name' => 'Acme Admin',
        'email' => 'admin@acme.test',
        'password' => 'strong-password',
        'password_confirmation' => 'strong-password',
    ], $overrides);
}

it('renders the registration page', function () {
    $this->get(central('/register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
});

it('registers a tenant + admin, starts a 14-day trial, and logs in', function () {
    Notification::fake();

    $this->post(central('/register'), validRegistration())
        ->assertRedirect('http://acme.'.config('tenancy.central_domain').'/dashboard');

    $tenant = Tenant::where('slug', 'acme')->sole();
    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->trial_ends_at->isBetween(now()->addDays(13), now()->addDays(15)))->toBeTrue();

    $user = User::where('email', 'admin@acme.test')->sole();
    expect($user->tenant_id)->toBe($tenant->id);
    $this->assertAuthenticatedAs($user);

    // Tenant-admin role granted within the tenant's team.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    expect($user->fresh()->hasRole(Role::TenantAdmin->value))->toBeTrue();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('lets the freshly registered admin reach their dashboard', function () {
    $this->post(central('/register'), validRegistration());

    $this->get('http://acme.'.config('tenancy.central_domain').'/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));
});

it('never lets the registration payload inject tenant_id (no super-admin minting)', function () {
    // tenant_id=null would mint a super-admin via the isSuperAdmin() invariant;
    // the action must take tenant_id from the new tenant, never from input.
    $this->post(central('/register'), validRegistration(['tenant_id' => null]));

    $tenant = Tenant::where('slug', 'acme')->sole();
    $user = User::where('email', 'admin@acme.test')->sole();

    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->isSuperAdmin())->toBeFalse();
});

it('rejects a reserved slug', function () {
    $this->post(central('/register'), validRegistration(['slug' => 'admin']))
        ->assertSessionHasErrors('slug');

    expect(Tenant::where('slug', 'admin')->exists())->toBeFalse();
});

it('rejects a duplicate slug', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->post(central('/register'), validRegistration(['email' => 'other@acme.test']))
        ->assertSessionHasErrors('slug');
});

it('rejects an invalid slug format', function () {
    $this->post(central('/register'), validRegistration(['slug' => 'Bad Slug!']))
        ->assertSessionHasErrors('slug');
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'admin@acme.test']);

    $this->post(central('/register'), validRegistration(['slug' => 'fresh']))
        ->assertSessionHasErrors('email');
});

it('does not create a tenant when validation fails', function () {
    $this->post(central('/register'), validRegistration(['slug' => 'www']));

    expect(Tenant::count())->toBe(0)
        ->and(User::count())->toBe(0);
});
