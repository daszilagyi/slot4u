<?php

use App\Actions\Domain\DeleteTenantDomain;
use App\Actions\Domain\ProvisionTenantDomain;
use App\Actions\Domain\VerifyTenantDomain;
use App\Actions\Tenant\SetTenantFeature;
use App\Enums\DomainProvisioningStatus;
use App\Enums\Feature;
use App\Enums\Role;
use App\Jobs\DeprovisionCustomHostname;
use App\Jobs\ProvisionCustomHostname;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\Domain\CloudflareCustomHostnameProvisioner;
use App\Services\Domain\CustomHostnameProvisioner;
use App\Services\Domain\DnsResolver;
use App\Services\Domain\ProvisioningFailed;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature as Pennant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\FakeCustomHostnameProvisioner;
use Tests\Fixtures\FakeDnsResolver;

/*
 * Custom hostname provisioning (SLO-135). Owning a domain and being able to
 * SERVE it are separate facts: Cloudflare only answers for hostnames registered
 * on the zone, so a verified domain that was never registered returns 1014
 * instead of the booking page. These cover the second half — and, above all,
 * that a provider refusal never silently undoes the tenant's ownership.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);

    $this->provisioner = new FakeCustomHostnameProvisioner;
    app()->instance(CustomHostnameProvisioner::class, $this->provisioner);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function provTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);

    app(SetTenantFeature::class)($tenant, Feature::CustomDomain, true);
    Pennant::flushCache();

    return $tenant;
}

function provAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

// ------------------------------------------------------- verification → edge

it('registers a domain at the edge as soon as ownership is proven', function () {
    Queue::fake();

    $tenant = provTenant();
    $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);
    $this->dns->setTxt('_slot4u-verify.foglalas.acme.hu', $domain->verification_token);

    app(VerifyTenantDomain::class)($domain);

    Queue::assertPushed(ProvisionCustomHostname::class);
});

it('does not register anything for a domain that failed verification', function () {
    Queue::fake();

    $tenant = provTenant();
    $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    app(VerifyTenantDomain::class)($domain);

    Queue::assertNotPushed(ProvisionCustomHostname::class);
});

// ------------------------------------------------------------ the provision

it('marks a domain live once the certificate is active', function () {
    $tenant = provTenant();
    $domain = TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    expect(app(ProvisionTenantDomain::class)($domain))->toBeTrue();

    $domain->refresh();

    expect($domain->provisioning_status)->toBe(DomainProvisioningStatus::Active)
        ->and($domain->certificate_status)->toBe('active')
        ->and($domain->provider_hostname_id)->not->toBeNull()
        ->and($domain->isLive())->toBeTrue()
        ->and($this->provisioner->provisioned)->toBe(['foglalas.acme.hu']);
});

it('keeps a domain pending while the certificate is still validating', function () {
    $this->provisioner->certificateStatus = 'pending_validation';

    $tenant = provTenant();
    $domain = TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    expect(app(ProvisionTenantDomain::class)($domain))->toBeFalse();

    $domain->refresh();

    expect($domain->provisioning_status)->toBe(DomainProvisioningStatus::Pending)
        ->and($domain->certificate_status)->toBe('pending_validation')
        ->and($domain->isLive())->toBeFalse();
});

it('never revokes ownership when the provider refuses', function () {
    $this->provisioner->failWith = 'Zone rate limit reached (1234)';

    $tenant = provTenant();
    $domain = TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);
    $verifiedAt = $domain->verified_at;

    expect(app(ProvisionTenantDomain::class)($domain))->toBeFalse();

    $domain->refresh();

    // The tenant proved the domain is theirs; Cloudflare having a bad day does
    // not change that, and the failure has to stay visible and retryable.
    expect($domain->verified_at?->timestamp)->toBe($verifiedAt?->timestamp)
        ->and($domain->provisioning_status)->toBe(DomainProvisioningStatus::Failed)
        ->and($domain->provisioning_error)->toContain('1234');
});

it('registers nothing where no provider is configured', function () {
    $this->provisioner->configured = false;

    $tenant = provTenant();
    $domain = TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    expect(app(ProvisionTenantDomain::class)($domain))->toBeFalse();

    $domain->refresh();

    // Not "failed" — nothing was attempted, and pretending otherwise would show
    // every dev and CI run a broken domain.
    expect($domain->provisioning_status)->toBeNull()
        ->and($this->provisioner->provisioned)->toBe([]);
});

it('registers nothing for a domain whose ownership is unproven', function () {
    $tenant = provTenant();
    $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    expect(app(ProvisionTenantDomain::class)($domain))->toBeFalse();
    expect($this->provisioner->provisioned)->toBe([]);
});

// ------------------------------------------------------------------ release

it('releases the hostname at the edge when the domain is given up', function () {
    Queue::fake();

    $tenant = provTenant();
    $domain = TenantDomain::factory()->provisioned()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    app(DeleteTenantDomain::class)($domain);

    Queue::assertPushed(DeprovisionCustomHostname::class);
});

it('has nothing to release for a domain that was never registered', function () {
    Queue::fake();

    $tenant = provTenant();
    $domain = TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    app(DeleteTenantDomain::class)($domain);

    Queue::assertNotPushed(DeprovisionCustomHostname::class);
});

// --------------------------------------------------------- the admin panel

it('lets an admin retry a refused registration', function () {
    $tenant = provTenant();
    $domain = TenantDomain::factory()->provisioningFailed()->create([
        'tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu',
    ]);

    $this->actingAs(provAdmin($tenant))
        ->post(tenantHost('acme', "/settings/domains/{$domain->id}/provision"))
        ->assertRedirect();

    expect($domain->fresh()->provisioning_status)->toBe(DomainProvisioningStatus::Active);
});

it('refuses to register a domain whose ownership is unproven', function () {
    $tenant = provTenant();
    $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    $this->actingAs(provAdmin($tenant))
        ->post(tenantHost('acme', "/settings/domains/{$domain->id}/provision"))
        ->assertStatus(422);
});

it('404s a retry aimed at another tenant domain', function () {
    $acme = provTenant('acme');
    $bolt = provTenant('bolt');
    $foreign = TenantDomain::factory()->verified()->create(['tenant_id' => $bolt->id, 'domain' => 'foglalas.bolt.hu']);

    $this->actingAs(provAdmin($acme))
        ->post(tenantHost('acme', "/settings/domains/{$foreign->id}/provision"))
        ->assertNotFound();
});

it('shows the certificate state on the domains page', function () {
    $tenant = provTenant();
    TenantDomain::factory()->provisioningPending()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    $this->actingAs(provAdmin($tenant))
        ->get(tenantHost('acme', '/settings/domains'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('provisioning_enabled', true)
            ->where('domains.0.provisioning_status', 'pending')
            ->where('domains.0.live', false));
});

// ---------------------------------------------------------- the cron sweep

it('flips a pending domain to live once the certificate finishes', function () {
    $tenant = provTenant();
    $domain = TenantDomain::factory()->provisioningPending()->create([
        'tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu',
    ]);

    $this->artisan('domains:refresh-certificates')->assertSuccessful();

    expect($domain->fresh()->provisioning_status)->toBe(DomainProvisioningStatus::Active);
});

it('polls nothing where no provider is configured', function () {
    $this->provisioner->configured = false;

    $tenant = provTenant();
    $domain = TenantDomain::factory()->provisioningPending()->create([
        'tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu',
    ]);

    $this->artisan('domains:refresh-certificates')->assertSuccessful();

    expect($domain->fresh()->provisioning_status)->toBe(DomainProvisioningStatus::Pending);
});

it('leaves an already active domain alone', function () {
    $tenant = provTenant();
    TenantDomain::factory()->provisioned()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    $this->artisan('domains:refresh-certificates')->assertSuccessful();

    // Only pending/failed rows are polled — an active domain must not cost an
    // API call on every sweep.
    expect($this->provisioner->provisioned)->toBe([]);
});

// ------------------------------------------------------- the Cloudflare client

function cfProvisioner(): CloudflareCustomHostnameProvisioner
{
    return new CloudflareCustomHostnameProvisioner('test-token', 'zone-123');
}

function cfDomain(): TenantDomain
{
    $domain = new TenantDomain;
    $domain->domain = 'foglalas.acme.hu';

    return $domain;
}

it('registers a hostname Cloudflare does not know yet', function () {
    Http::fake([
        '*/custom_hostnames?hostname=*' => Http::response(['success' => true, 'result' => []]),
        '*/custom_hostnames' => Http::response([
            'success' => true,
            'result' => ['id' => 'cf-1', 'hostname' => 'foglalas.acme.hu', 'ssl' => ['status' => 'pending_validation']],
        ]),
    ]);

    $hostname = cfProvisioner()->provision(cfDomain());

    expect($hostname->id)->toBe('cf-1')
        ->and($hostname->certificateStatus)->toBe('pending_validation')
        ->and($hostname->isActive())->toBeFalse();
});

it('reuses an existing registration instead of creating a second one', function () {
    Http::fake([
        '*/custom_hostnames?hostname=*' => Http::response([
            'success' => true,
            'result' => [['id' => 'cf-existing', 'hostname' => 'foglalas.acme.hu', 'ssl' => ['status' => 'active']]],
        ]),
    ]);

    $hostname = cfProvisioner()->provision(cfDomain());

    expect($hostname->id)->toBe('cf-existing')
        ->and($hostname->isActive())->toBeTrue();

    // A retry after a timed-out create must not register the hostname twice.
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('ignores a lookup hit for a different hostname', function () {
    Http::fake([
        '*/custom_hostnames?hostname=*' => Http::response([
            'success' => true,
            // Cloudflare's hostname filter matches by prefix on some plans.
            'result' => [['id' => 'cf-other', 'hostname' => 'foglalas.acme.hu.evil.example', 'ssl' => ['status' => 'active']]],
        ]),
        '*/custom_hostnames' => Http::response([
            'success' => true,
            'result' => ['id' => 'cf-new', 'hostname' => 'foglalas.acme.hu', 'ssl' => ['status' => 'pending_validation']],
        ]),
    ]);

    expect(cfProvisioner()->provision(cfDomain())->id)->toBe('cf-new');
});

it('surfaces a Cloudflare refusal with its own message', function () {
    Http::fake([
        '*/custom_hostnames?hostname=*' => Http::response(['success' => true, 'result' => []]),
        '*/custom_hostnames' => Http::response([
            'success' => false,
            'errors' => [['code' => 1406, 'message' => 'Custom hostname limit reached']],
        ], 400),
    ]);

    expect(fn () => cfProvisioner()->provision(cfDomain()))
        ->toThrow(ProvisioningFailed::class, 'Custom hostname limit reached (1406)');
});

it('treats a 200 with success false as a refusal', function () {
    Http::fake([
        '*' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 200),
    ]);

    expect(fn () => cfProvisioner()->provision(cfDomain()))
        ->toThrow(ProvisioningFailed::class, 'Authentication error (10000)');
});

it('treats an already deleted hostname as released', function () {
    Http::fake(['*' => Http::response(['success' => false, 'errors' => []], 404)]);

    cfProvisioner()->deprovision('cf-gone');
})->throwsNoExceptions();

it('is not configured without a token or a zone', function () {
    expect((new CloudflareCustomHostnameProvisioner(null, 'zone-123'))->isConfigured())->toBeFalse()
        ->and((new CloudflareCustomHostnameProvisioner('token', null))->isConfigured())->toBeFalse()
        ->and((new CloudflareCustomHostnameProvisioner('token', 'zone-123'))->isConfigured())->toBeTrue();
});
