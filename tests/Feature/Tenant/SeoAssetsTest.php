<?php

use App\Enums\Feature;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Services\Seo\OgImageGenerator;
use App\Settings\TenantBranding;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Storage;

afterEach(function () {
    app(TenantManager::class)->forget();
});

function seoTenant(string $slug = 'acme', array $attributes = []): Tenant
{
    return Tenant::factory()->active()->create(array_merge(['slug' => $slug], $attributes));
}

function seoEnableBranding(Tenant $tenant): void
{
    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::Branding, 'enabled' => true]);
    app(TenantManager::class)->forget();
}

function seoServiceFor(Tenant $tenant, string $name, bool $active = true): Service
{
    return Service::factory()->forTenant($tenant)->create(['name' => $name, 'active' => $active]);
}

it('serves a tenant-scoped sitemap.xml with the homepage, /book and active service links', function () {
    $tenant = seoTenant('acme');
    seoServiceFor($tenant, 'Svédmasszázs');
    seoServiceFor($tenant, 'Rejtett szolgáltatás', active: false);

    $other = seoTenant('other');
    $foreign = seoServiceFor($other, 'Foreign service');

    $response = $this->get(tenantHost('acme', '/sitemap.xml'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $body = $response->getContent();
    expect($body)->toContain('<loc>http://acme.'.config('tenancy.central_domain').'</loc>');
    expect($body)->toContain('/book</loc>');
    $active = Service::withoutGlobalScopes()->where('name', 'Svédmasszázs')->first();
    expect($body)->toContain('/book?service='.$active->id.'</loc>');
    // Inactive service and another tenant's service must not surface.
    expect($body)->not->toContain('service='.$foreign->id.'</loc>');
});

it('serves robots.txt for an active tenant with a Sitemap line and admin disallows', function () {
    seoTenant('acme');

    $response = $this->get(tenantHost('acme', '/robots.txt'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/plain');

    $body = $response->getContent();
    expect($body)->toContain('Sitemap: http://acme.'.config('tenancy.central_domain').'/sitemap.xml');
    expect($body)->toContain('Disallow: /my/');
    expect($body)->toContain('Allow: /');
});

it('disallows everything in robots.txt for a suspended tenant (outside ensure.tenant.active)', function () {
    Tenant::factory()->suspended()->create(['slug' => 'frozen']);

    $response = $this->get(tenantHost('frozen', '/robots.txt'));

    // 200, NOT the 503 status page — robots.txt sits outside ensure.tenant.active.
    $response->assertOk();
    expect($response->getContent())->toBe("User-agent: *\nDisallow: /\n");
    expect($response->getContent())->not->toContain('Sitemap:');
});

it('renders and caches a branded PNG OG image on the public disk', function () {
    Storage::fake('public');
    $tenant = seoTenant('acme', ['name' => 'Acme Szalon', 'branding' => ['primary_color' => '#123456']]);
    seoEnableBranding($tenant);

    $response = $this->get(tenantHost('acme', '/og-image.png'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png');

    $body = $response->streamedContent();
    // PNG signature.
    expect(substr($body, 0, 4))->toBe("\x89PNG");

    // The file is cached on the public disk under og/{id}-{hash}.png.
    $relative = 'og/'.$tenant->id.'-'.app(OgImageGenerator::class)->cacheKey($tenant->fresh()).'.png';
    expect(Storage::disk('public')->exists($relative))->toBeTrue();

    // A second request is served from the cached file (still a valid PNG).
    $second = $this->get(tenantHost('acme', '/og-image.png'));
    $second->assertOk();
    expect(substr($second->streamedContent(), 0, 4))->toBe("\x89PNG");
});

it('derives a stable cacheKey that changes only when branding changes', function () {
    // A fresh generator per assertion mirrors production (one instance per request);
    // the generator memoises the resolved branding for its own lifetime (SLO-107).
    Storage::fake('public');
    $tenant = seoTenant('acme', ['name' => 'Acme', 'branding' => ['primary_color' => '#123456']]);
    seoEnableBranding($tenant);

    $key = app(OgImageGenerator::class)->cacheKey($tenant);
    expect(app(OgImageGenerator::class)->cacheKey($tenant->fresh()))->toBe($key);

    $tenant->update(['branding' => ['primary_color' => '#abcdef']]);
    expect(app(OgImageGenerator::class)->cacheKey($tenant->fresh()))->not->toBe($key);
});

it('picks the share image text colour by the same rule as the booking page', function (string $hex) {
    // Two formulas used to answer "what text is readable on this brand?" — the
    // page's (WCAG luminance > 0.3) and the share image's own (raw > 0.6). On
    // orange (#FF8800) they disagreed: black buttons on the page, white text on
    // the share image at 2.3:1, cached until the tenant rebranded (SLO-180).
    // image, cached until the tenant rebranded (SLO-180).
    expect(app(OgImageGenerator::class)->usesDarkText($hex))
        ->toBe(TenantBranding::readableForeground($hex) === '#000000');
})->with(['#FF8800', '#0099FF', '#FFA000', '#6366f1', '#22DECB', '#0D1B2A', '#F4B942', '#E0457B', '#FFFFFF', '#000000']);

it('⚠️ draws near-black text on an orange brand, where it used to draw white', function () {
    Storage::fake('public');
    $tenant = seoTenant('narancs', ['name' => 'Narancs Stúdió', 'branding' => ['primary_color' => '#FF8800']]);
    seoEnableBranding($tenant);

    $this->get(tenantHost('narancs', '/og-image.png'))->assertOk();

    $relative = 'og/'.$tenant->id.'-'.app(OgImageGenerator::class)->cacheKey($tenant->fresh()).'.png';
    $img = imagecreatefromstring(Storage::disk('public')->get($relative));

    $dark = 0;
    $white = 0;
    for ($x = 0; $x < imagesx($img); $x += 2) {
        for ($y = 0; $y < imagesy($img); $y += 2) {
            $rgb = imagecolorat($img, $x, $y);
            $dark += (int) ($rgb === 0x111111);
            $white += (int) ($rgb === 0xFFFFFF);
        }
    }

    expect($dark)->toBeGreaterThan(100)
        ->and($white)->toBe(0);
});

it('versions the cache key, so a change in how the image is drawn reaches every cached file', function () {
    Storage::fake('public');
    $tenant = seoTenant('acme', ['name' => 'Acme', 'branding' => ['primary_color' => '#FF8800']]);
    seoEnableBranding($tenant);

    // The pre-SLO-180 key: branding inputs only. Keeping it would serve the
    // white-on-orange file forever to a tenant that never rebrands.
    $unversioned = substr(sha1('Acme|#FF8800|'), 0, 12);

    expect(app(OgImageGenerator::class)->cacheKey($tenant->fresh()))->not->toBe($unversioned);
});

it('gates the OG image branding behind feature_branding (default look when off)', function () {
    // With branding off (base-plan default), the custom colour/logo must not feed
    // the image or its cacheKey — the OG share preview matches the default-branded
    // look, consistent with the shared prop + cover gates (SLO-90/SLO-107).
    Storage::fake('public');
    $branded = seoTenant('acme', ['name' => 'Acme', 'branding' => ['primary_color' => '#123456']]);
    $plain = seoTenant('plain', ['name' => 'Acme']);

    // A stored custom colour is ignored while the feature is off → same key as a
    // tenant with no branding at all (only the shared name differs, which matches).
    expect(app(OgImageGenerator::class)->cacheKey($branded))
        ->toBe(app(OgImageGenerator::class)->cacheKey($plain));

    // Changing the (ungated) custom colour does not bust the cache when off.
    $key = app(OgImageGenerator::class)->cacheKey($branded);
    $branded->update(['branding' => ['primary_color' => '#abcdef']]);
    expect(app(OgImageGenerator::class)->cacheKey($branded->fresh()))->toBe($key);

    // Turning the feature on surfaces the custom colour → a fresh key.
    seoEnableBranding($branded);
    expect(app(OgImageGenerator::class)->cacheKey($branded->fresh()))->not->toBe($key);
});
