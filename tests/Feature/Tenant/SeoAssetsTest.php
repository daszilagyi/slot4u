<?php

use App\Models\Service;
use App\Models\Tenant;
use App\Services\Seo\OgImageGenerator;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Storage;

afterEach(function () {
    app(TenantManager::class)->forget();
});

function seoTenant(string $slug = 'acme', array $attributes = []): Tenant
{
    return Tenant::factory()->active()->create(array_merge(['slug' => $slug], $attributes));
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
    Storage::fake('public');
    $tenant = seoTenant('acme', ['name' => 'Acme', 'branding' => ['primary_color' => '#123456']]);
    $generator = app(OgImageGenerator::class);

    $key = $generator->cacheKey($tenant);
    expect($generator->cacheKey($tenant->fresh()))->toBe($key);

    $tenant->update(['branding' => ['primary_color' => '#abcdef']]);
    expect($generator->cacheKey($tenant->fresh()))->not->toBe($key);
});
