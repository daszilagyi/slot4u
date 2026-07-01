<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

/** Seeds a session as if the superadmin were impersonating $tenant. */
function impersonating(Tenant $tenant): array
{
    return ['impersonation' => ['tenant_id' => $tenant->id, 'tenant_name' => $tenant->name]];
}

it('starts impersonation: audits the superadmin and bounces to the tenant', function () {
    $admin = superAdmin();
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);

    $this->actingAs($admin)
        ->post(superUrl("/tenants/{$tenant->id}/impersonate"))
        ->assertRedirect(tenantHost('acme', '/dashboard'))
        ->assertSessionHas('impersonation.tenant_id', $tenant->id);

    expect(AuditLog::where('action', AuditAction::ImpersonationStarted->value)
        ->where('user_id', $admin->id)
        ->where('auditable_id', $tenant->id)
        ->where('tenant_id', $tenant->id)
        ->exists())->toBeTrue();
});

it('lets an impersonating superadmin into the tenant dashboard with the banner prop', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);

    $this->actingAs(superAdmin())
        ->withSession(impersonating($tenant))
        ->get(tenantHost('acme', '/dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')
            ->where('impersonation.tenant.id', $tenant->id)
            ->where('impersonation.tenant.name', 'Acme')
            ->where('impersonation.stopUrl', '/impersonation'));
});

it('still redirects a non-impersonating superadmin away from a tenant', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())
        ->get(tenantHost('acme', '/dashboard'))
        ->assertRedirect(superUrl('/'));
});

it('scopes impersonation to a single tenant (no access to another tenant)', function () {
    $a = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);
    Tenant::factory()->active()->create(['slug' => 'globex']);

    $this->actingAs(superAdmin())
        ->withSession(impersonating($a))
        ->get(tenantHost('globex', '/dashboard'))
        ->assertRedirect(superUrl('/'));
});

it('does not leak the banner prop outside the impersonated tenant', function () {
    $a = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);
    Tenant::factory()->active()->create(['slug' => 'globex']);

    // Impersonating Acme, but visiting Globex's public home: no banner.
    $this->actingAs(superAdmin())
        ->withSession(impersonating($a))
        ->get(tenantHost('globex', '/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('impersonation', null));
});

it('stops impersonation: audits the exit, clears the session, returns to admin', function () {
    $admin = superAdmin();
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Acme']);

    $this->actingAs($admin)
        ->withSession(impersonating($tenant))
        ->delete(tenantHost('acme', '/impersonation'))
        ->assertRedirect(superUrl("/tenants/{$tenant->id}"))
        ->assertSessionMissing('impersonation');

    expect(AuditLog::where('action', AuditAction::ImpersonationStopped->value)
        ->where('user_id', $admin->id)
        ->where('auditable_id', $tenant->id)
        ->exists())->toBeTrue();
});

it('lets a superadmin exit impersonation even on a suspended tenant', function () {
    $admin = superAdmin();
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'frozen', 'name' => 'Frozen']);

    // The stop route sits outside ensure.tenant.active, so the exit works.
    $this->actingAs($admin)
        ->withSession(impersonating($tenant))
        ->delete(tenantHost('frozen', '/impersonation'))
        ->assertRedirect(superUrl("/tenants/{$tenant->id}"))
        ->assertSessionMissing('impersonation');
});

it('forbids a tenant user from starting impersonation (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->post(superUrl("/tenants/{$tenant->id}/impersonate"))
        ->assertForbidden();
});

it('redirects a guest who tries to start impersonation to login', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->post(superUrl("/tenants/{$tenant->id}/impersonate"))
        ->assertRedirectContains('/login');
});

it('cannot start impersonation on a suspended tenant (403)', function () {
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'frozen']);

    $this->actingAs(superAdmin())
        ->post(superUrl("/tenants/{$tenant->id}/impersonate"))
        ->assertForbidden();

    expect(session()->has('impersonation'))->toBeFalse();
});

it('cannot impersonate an archived tenant (404)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $tenant->delete(); // soft-delete = archived; route binding excludes it.

    $this->actingAs(superAdmin())
        ->post(superUrl("/tenants/{$tenant->id}/impersonate"))
        ->assertNotFound();
});
