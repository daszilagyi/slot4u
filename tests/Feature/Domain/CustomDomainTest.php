<?php

use App\Actions\Domain\AddTenantDomain;
use App\Actions\Domain\DeleteTenantDomain;
use App\Actions\Domain\SetPrimaryTenantDomain;
use App\Actions\Domain\VerifyTenantDomain;
use App\Actions\Tenant\SetTenantFeature;
use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\Domain\DnsResolver;
use App\Tenancy\CustomDomainResolver;
use App\Tenancy\DomainName;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantPublicUrl;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Laravel\Pennant\Feature as Pennant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\FakeDnsResolver;

/*
 * Custom tenant domains (SLO-42): a verified domain serves the tenant's public
 * surface, an unverified or feature-less one serves nothing, the canonical
 * subdomain keeps working throughout, and the domain a tenant hands out (emails,
 * canonical tags) follows its primary host.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A tenant allowed to use custom domains. */
function domTenant(string $slug = 'acme', bool $feature = true): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);

    app(SetTenantFeature::class)($tenant, Feature::CustomDomain, $feature);
    Pennant::flushCache();

    return $tenant;
}

function domAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

/** The tenant's canonical subdomain, as the test environment configures it. */
function domSubdomain(string $slug = 'acme'): string
{
    return $slug.'.'.config('tenancy.central_domain');
}

/** The scheme generated links are built with (config app.url), not the request's. */
function domScheme(): string
{
    return parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
}

/** A verified custom domain for a tenant, written without a bound tenant. */
function domVerified(Tenant $tenant, string $host, bool $primary = false): TenantDomain
{
    return TenantDomain::factory()
        ->when($primary, fn ($f) => $f->primary(), fn ($f) => $f->verified())
        ->create(['tenant_id' => $tenant->id, 'domain' => $host]);
}

// ---------------------------------------------------------------- resolution

it('serves the tenant public page on a verified custom domain', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu');

    $this->get('http://foglalas.acme.hu/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Home')
            ->where('profile.name', $tenant->name));
});

it('keeps the canonical subdomain working alongside a custom domain', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu', primary: true);

    $this->get(tenantHost('acme'))->assertOk();
});

it('serves nothing on an unverified domain', function () {
    $tenant = domTenant();
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    $this->get('http://foglalas.acme.hu/')->assertNotFound();
});

it('stops serving a custom domain when the feature is switched off', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu');

    $this->get('http://foglalas.acme.hu/')->assertOk();

    app(SetTenantFeature::class)($tenant, Feature::CustomDomain, false);
    Pennant::flushCache();
    app(CustomDomainResolver::class)->forget('foglalas.acme.hu');

    // The acceptance criterion: the domain goes dark, the subdomain does not.
    $this->get('http://foglalas.acme.hu/')->assertNotFound();
    $this->get(tenantHost('acme'))->assertOk();
});

it('serves nothing on an unknown host', function () {
    domTenant();

    $this->get('http://sosemvolt.example.org/')->assertNotFound();
});

it('resolves a custom domain to its own tenant, not another', function () {
    $acme = domTenant('acme');
    domTenant('bolt');
    domVerified($acme, 'foglalas.acme.hu');

    $this->get('http://foglalas.acme.hu/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('profile.name', $acme->name));
});

it('stops serving a host as soon as the domain is deleted', function () {
    $tenant = domTenant();
    $domain = domVerified($tenant, 'foglalas.acme.hu');

    $this->get('http://foglalas.acme.hu/')->assertOk();

    app(DeleteTenantDomain::class)($domain);

    // Would still be served from the resolver cache if the delete had not
    // invalidated it.
    $this->get('http://foglalas.acme.hu/')->assertNotFound();
});

it('binds the session cookie to the custom host, not the central domain', function () {
    config(['session.domain' => '.'.config('tenancy.central_domain')]);

    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu');

    $response = $this->get('http://foglalas.acme.hu/')->assertOk();

    $session = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    // A cookie scoped to .{central} would simply not be sent back by a browser
    // on foglalas.acme.hu — no session there means no CSRF token and a 419 on
    // every booking submission.
    expect($session)->not->toBeNull()
        ->and($session->getDomain())->toBeNull();
});

it('leaves the shared session cookie domain alone on the subdomain', function () {
    $expected = '.'.config('tenancy.central_domain');
    config(['session.domain' => $expected]);

    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu');

    $response = $this->get(tenantHost('acme'))->assertOk();

    $session = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($session?->getDomain())->toBe($expected);
});

// ------------------------------------ Cloudflare-forwarded host (SLO-135)

it('resolves the tenant from the forwarded host header behind Cloudflare', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu');

    // What Cloudflare for SaaS actually delivers once the Origin Rule has
    // rewritten Host to the fallback origin: the visitor's real hostname
    // arrives in the private header instead.
    $response = $this->call('GET', 'http://customers.'.config('tenancy.central_domain').'/', server: [
        'REMOTE_ADDR' => '173.245.48.1', // a Cloudflare edge range
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_SLOT4U_ORIGINAL_HOST' => 'foglalas.acme.hu',
    ]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Home')
        ->where('profile.name', $tenant->name));
});

it('ignores a forwarded host header from an untrusted peer', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu');

    // Believing this would let anyone serve any tenant's domain by setting a
    // header. The fallback-origin host itself is reserved, so it 404s.
    $this->call('GET', 'http://customers.'.config('tenancy.central_domain').'/', server: [
        'REMOTE_ADDR' => '203.0.113.99', // not Cloudflare
        'HTTP_X_SLOT4U_ORIGINAL_HOST' => 'foglalas.acme.hu',
    ])->assertNotFound();
});

it('generates links on the forwarded host, not the fallback origin', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu', primary: true);

    $response = $this->call('GET', 'http://customers.'.config('tenancy.central_domain').'/', server: [
        'REMOTE_ADDR' => '173.245.48.1',
        'HTTP_X_SLOT4U_ORIGINAL_HOST' => 'foglalas.acme.hu',
    ]);

    $response->assertInertia(fn ($page) => $page
        ->where('og_image', fn (string $url): bool => str_contains($url, 'foglalas.acme.hu')));
});

it('never lets a tenant claim the fallback origin slug', function () {
    // `customers` is reserved (config/tenancy.php): every custom-hostname
    // request lands on that host, so a tenant owning the slug would swallow
    // all of them.
    expect(config('tenancy.reserved_subdomains'))->toContain('customers');
});

// ------------------------------------------------------------------ link URLs

it('keeps generated absolute urls on the host the visitor used', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu', primary: true);

    $this->get('http://foglalas.acme.hu/')
        ->assertInertia(fn ($page) => $page
            // url()-built asset link follows the request host…
            ->where('og_image', fn (string $url): bool => str_contains($url, 'foglalas.acme.hu'))
            // …while the canonical names the primary host explicitly.
            ->where('canonical_url', domScheme().'://foglalas.acme.hu/'));
});

it('points the canonical at the subdomain while no custom domain is primary', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu');

    $this->get('http://foglalas.acme.hu/')
        ->assertInertia(fn ($page) => $page->where('canonical_url', domScheme().'://'.domSubdomain().'/'));
});

it('addresses a tenant by its subdomain while it has no custom domain', function () {
    $tenant = domTenant();

    expect(app(TenantPublicUrl::class)->host($tenant))->toBe(domSubdomain());
});

it('addresses a tenant by its primary custom domain in generated links', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu', primary: true);

    // Resolution is memoised for the lifetime of a request/job by design, so
    // each case asserts against a container that has not looked it up yet.
    expect(app(TenantPublicUrl::class)->to($tenant, '/my/bookings'))
        ->toBe(domScheme().'://foglalas.acme.hu/my/bookings');
});

it('ignores a primary custom domain once the feature is gone', function () {
    $tenant = domTenant();
    domVerified($tenant, 'foglalas.acme.hu', primary: true);

    app(SetTenantFeature::class)($tenant, Feature::CustomDomain, false);
    Pennant::flushCache();

    expect(app(TenantPublicUrl::class)->host($tenant))->toBe(domSubdomain());
});

// -------------------------------------------------------------- verification

it('verifies a domain from its TXT record', function () {
    $tenant = domTenant();
    $domain = app(AddTenantDomain::class)($tenant, 'foglalas.acme.hu');

    $this->dns->setTxt('_slot4u-verify.foglalas.acme.hu', $domain->verification_token);

    expect(app(VerifyTenantDomain::class)($domain))->toBeTrue();

    expect($domain->fresh()->verified_at)->not->toBeNull()
        ->and($domain->fresh()->last_error)->toBeNull();
});

it('reports a missing TXT record instead of verifying', function () {
    $tenant = domTenant();
    $domain = app(AddTenantDomain::class)($tenant, 'foglalas.acme.hu');

    expect(app(VerifyTenantDomain::class)($domain))->toBeFalse();

    expect($domain->fresh()->verified_at)->toBeNull()
        ->and($domain->fresh()->last_error)->toBe('txt_record_missing')
        ->and($domain->fresh()->last_checked_at)->not->toBeNull();
});

it('distinguishes a wrong TXT value from a missing one', function () {
    $tenant = domTenant();
    $domain = app(AddTenantDomain::class)($tenant, 'foglalas.acme.hu');

    $this->dns->setTxt('_slot4u-verify.foglalas.acme.hu', 'valaki-mas-tokenje');

    expect(app(VerifyTenantDomain::class)($domain))->toBeFalse();
    expect($domain->fresh()->last_error)->toBe('txt_record_mismatch');
});

it('accepts a TXT value the resolver hands back in quotes', function () {
    $tenant = domTenant();
    $domain = app(AddTenantDomain::class)($tenant, 'foglalas.acme.hu');

    $this->dns->setTxt('_slot4u-verify.foglalas.acme.hu', '"'.$domain->verification_token.'"');

    expect(app(VerifyTenantDomain::class)($domain))->toBeTrue();
});

// -------------------------------------------------------------- normalisation

it('canonicalises a host before storing it', function (string $input, string $stored) {
    expect(DomainName::normalize($input))->toBe($stored);
})->with([
    ['FOGLALAS.Acme.HU', 'foglalas.acme.hu'],
    ['foglalas.acme.hu.', 'foglalas.acme.hu'],
    ['https://foglalas.acme.hu/book?x=1', 'foglalas.acme.hu'],
    ['foglalas.acme.hu:8443', 'foglalas.acme.hu'],
    ['  foglalas.acme.hu  ', 'foglalas.acme.hu'],
    ['fóglalás.acme.hu', 'xn--fglals-tta3l.acme.hu'],
]);

it('rejects things that are not registrable hostnames', function (string $input) {
    expect(DomainName::normalize($input))->toBeNull();
})->with([
    'bare label' => ['localhost'],
    'ip literal' => ['192.168.0.1'],
    'numeric tld' => ['acme.1'],
    'leading dash' => ['-acme.hu'],
    'empty' => ['   '],
]);

// -------------------------------------------------------------------- the UI

it('lets a tenant admin add a domain', function () {
    $tenant = domTenant();

    $this->actingAs(domAdmin($tenant))
        ->post(tenantHost('acme', '/settings/domains'), ['domain' => 'HTTPS://Foglalas.Acme.HU/'])
        ->assertRedirect();

    $domain = TenantDomain::withoutGlobalScopes()->sole();

    expect($domain->domain)->toBe('foglalas.acme.hu')
        ->and($domain->tenant_id)->toBe($tenant->id)
        ->and($domain->verified_at)->toBeNull()
        ->and($domain->verification_token)->not->toBeEmpty();
});

it('refuses a host inside slot4u own domain space', function () {
    $tenant = domTenant();

    $this->actingAs(domAdmin($tenant))
        ->post(tenantHost('acme', '/settings/domains'), ['domain' => 'masik.'.config('tenancy.central_domain')])
        ->assertSessionHasErrors('domain');

    expect(TenantDomain::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a host another tenant already claimed', function () {
    $acme = domTenant('acme');
    $bolt = domTenant('bolt');
    domVerified($bolt, 'foglalas.kozos.hu');

    $this->actingAs(domAdmin($acme))
        ->post(tenantHost('acme', '/settings/domains'), ['domain' => 'foglalas.kozos.hu'])
        ->assertSessionHasErrors('domain');

    expect(TenantDomain::withoutGlobalScopes()->where('domain', 'foglalas.kozos.hu')->sole()->tenant_id)
        ->toBe($bolt->id);
});

it('lists a tenant only its own domains', function () {
    $acme = domTenant('acme');
    $bolt = domTenant('bolt');
    domVerified($acme, 'foglalas.acme.hu');
    domVerified($bolt, 'foglalas.bolt.hu');

    $this->actingAs(domAdmin($acme))
        ->get(tenantHost('acme', '/settings/domains'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Domains/Index')
            ->has('domains', 1)
            ->where('domains.0.domain', 'foglalas.acme.hu'));
});

it('404s on another tenant domain id', function () {
    $acme = domTenant('acme');
    $bolt = domTenant('bolt');
    $foreign = domVerified($bolt, 'foglalas.bolt.hu');

    $this->actingAs(domAdmin($acme))
        ->delete(tenantHost('acme', "/settings/domains/{$foreign->id}"))
        ->assertNotFound();

    expect(TenantDomain::withoutGlobalScopes()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('403s the whole panel for a tenant without the feature', function () {
    $tenant = domTenant('acme', feature: false);

    $this->actingAs(domAdmin($tenant))
        ->get(tenantHost('acme', '/settings/domains'))
        ->assertForbidden();
});

it('403s a staff member without settings.edit', function () {
    $tenant = domTenant();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $employee = User::factory()->create(['tenant_id' => $tenant->id]);
    $employee->assignRole(Role::Employee->value);

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/settings/domains'))
        ->assertForbidden();
});

// ------------------------------------------------------------------- primary

it('promotes only one domain to primary at a time', function () {
    $tenant = domTenant();
    $first = domVerified($tenant, 'egy.acme.hu', primary: true);
    $second = domVerified($tenant, 'ketto.acme.hu');

    app(SetPrimaryTenantDomain::class)($second);

    expect($second->fresh()->is_primary)->toBeTrue()
        ->and($first->fresh()->is_primary)->toBeFalse();
});

it('refuses to make an unverified domain primary', function () {
    $tenant = domTenant();
    $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'foglalas.acme.hu']);

    $this->actingAs(domAdmin($tenant))
        ->post(tenantHost('acme', "/settings/domains/{$domain->id}/primary"))
        ->assertStatus(422);

    expect($domain->fresh()->is_primary)->toBeFalse();
});

it('leaves another tenant primary domain untouched when promoting', function () {
    $acme = domTenant('acme');
    $bolt = domTenant('bolt');
    $boltPrimary = domVerified($bolt, 'foglalas.bolt.hu', primary: true);
    $acmeDomain = domVerified($acme, 'foglalas.acme.hu');

    app(SetPrimaryTenantDomain::class)($acmeDomain);

    expect($boltPrimary->fresh()->is_primary)->toBeTrue();
});
