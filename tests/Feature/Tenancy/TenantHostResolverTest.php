<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Tenancy\TenantHostResolver;

function resolveHost(string $host): ?Tenant
{
    return app(TenantHostResolver::class)->resolve($host);
}

function hostFor(string $sub = ''): string
{
    return ($sub === '' ? '' : $sub.'.').config('tenancy.central_domain');
}

it('resolves a tenant from its subdomain host', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);

    expect(resolveHost(hostFor('acme'))?->id)->toBe($tenant->id);
});

it('resolves a suspended tenant (status gating is the caller\'s job)', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'status' => TenantStatus::Suspended]);

    expect(resolveHost(hostFor('acme'))?->id)->toBe($tenant->id);
});

it('returns null for the central apex host', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    expect(resolveHost(hostFor()))->toBeNull();
});

it('returns null for a reserved subdomain', function () {
    expect(resolveHost(hostFor('www')))->toBeNull()
        ->and(resolveHost(hostFor(config('tenancy.admin_subdomain'))))->toBeNull();
});

it('returns null for a multi-label subdomain', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    expect(resolveHost(hostFor('acme.staging')))->toBeNull();
});

it('returns null for an unknown slug', function () {
    expect(resolveHost(hostFor('nope')))->toBeNull();
});

it('returns null for an archived (soft-deleted) tenant', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $tenant->delete();

    expect(resolveHost(hostFor('acme')))->toBeNull();
});

it('does not confuse a lookalike apex host', function () {
    expect(resolveHost('evil'.config('tenancy.central_domain')))->toBeNull();
});
