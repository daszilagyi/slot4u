<?php

use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\BasePlanSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

it('records an audit entry with actor and old/new values when suspending', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = superAdmin();

    $this->actingAs($admin)->post(superUrl("/tenants/{$tenant->id}/suspend"));

    $log = AuditLog::query()->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('tenant.suspended')
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->tenant_id)->toBe($tenant->id)
        ->and($log->auditable_type)->toBe($tenant->getMorphClass())
        ->and($log->auditable_id)->toBe($tenant->id)
        ->and($log->old_values)->toBe(['status' => TenantStatus::Active->value])
        ->and($log->new_values)->toBe(['status' => TenantStatus::Suspended->value])
        ->and($log->ip_address)->not->toBeNull();
});

it('records activation and archival transitions', function () {
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'acme']);
    $admin = superAdmin();

    $this->actingAs($admin)->post(superUrl("/tenants/{$tenant->id}/activate"));
    $this->actingAs($admin)->post(superUrl("/tenants/{$tenant->id}/archive"));

    $actions = AuditLog::query()->orderBy('id')->pluck('action')->all();

    expect($actions)->toBe(['tenant.activated', 'tenant.archived']);
});

it('records a trial extension with the previous and new window', function () {
    $tenant = Tenant::factory()->active()->create(['trial_ends_at' => null]);

    $this->actingAs(superAdmin())->post(superUrl("/tenants/{$tenant->id}/extend-trial"));

    $log = AuditLog::query()->latest('id')->first();

    expect($log->action)->toBe('tenant.trial_extended')
        ->and($log->old_values['trial_ends_at'])->toBeNull()
        ->and($log->new_values['trial_ends_at'])->not->toBeNull()
        ->and($log->new_values['status'])->toBe(TenantStatus::Trial->value);
});

it('records a feature toggle with the previous and new state', function () {
    $this->seed(BasePlanSeeder::class);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())->post(superUrl("/tenants/{$tenant->id}/features"), [
        'feature' => Feature::OnlinePayment->value,
        'enabled' => true,
    ]);

    $log = AuditLog::query()->latest('id')->first();

    expect($log->action)->toBe('tenant.feature_toggled')
        ->and($log->tenant_id)->toBe($tenant->id)
        ->and($log->old_values)->toBe(['feature' => Feature::OnlinePayment->value, 'enabled' => null])
        ->and($log->new_values)->toBe(['feature' => Feature::OnlinePayment->value, 'enabled' => true]);
});

it('records a base-field update with the changed values', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'name' => 'Old', 'locale' => 'hu']);

    $this->actingAs(superAdmin())->put(superUrl("/tenants/{$tenant->id}"), [
        'name' => 'New Name',
        'slug' => 'acme',
        'timezone' => 'Europe/Budapest',
        'locale' => 'en',
    ]);

    $log = AuditLog::query()->latest('id')->first();

    expect($log->action)->toBe('tenant.updated')
        ->and($log->old_values['name'])->toBe('Old')
        ->and($log->old_values['locale'])->toBe('hu')
        ->and($log->new_values['name'])->toBe('New Name')
        ->and($log->new_values['locale'])->toBe('en');
});

it('writes no audit entry when a rejected update fails validation', function () {
    Tenant::factory()->active()->create(['slug' => 'taken']);
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())->put(superUrl("/tenants/{$tenant->id}"), [
        'name' => 'Acme',
        'slug' => 'taken',
        'timezone' => 'Europe/Budapest',
        'locale' => 'hu',
    ])->assertSessionHasErrors('slug');

    expect(AuditLog::query()->count())->toBe(0);
});

it('shows the audit-log viewer to a super-admin', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $this->actingAs(superAdmin())->post(superUrl("/tenants/{$tenant->id}/suspend"));

    $this->actingAs(superAdmin())
        ->get(superUrl('/audit-logs'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/AuditLogs/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'tenant.suspended'));
});

it('filters the audit log by action', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = superAdmin();
    $this->actingAs($admin)->post(superUrl("/tenants/{$tenant->id}/suspend"));
    $this->actingAs($admin)->post(superUrl("/tenants/{$tenant->id}/activate"));

    $this->actingAs($admin)
        ->get(superUrl('/audit-logs?action=tenant.activated'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'tenant.activated'));
});

it('forbids the audit-log viewer for a tenant user (403)', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get(superUrl('/audit-logs'))->assertForbidden();
});

it('is deliberately not tenant-scoped (platform-level log)', function () {
    // Guard: AuditLog is a superadmin-only platform log. Adding BelongsToTenant
    // would silently filter the superadmin viewer to the bound tenant (and risk
    // cross-tenant confusion if ever read in tenant context). Keep it off.
    expect(class_uses(AuditLog::class))->not->toContain(BelongsToTenant::class);
});
