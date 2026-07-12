<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Seo\OgImageGenerator;
use App\Tenancy\TenantManager;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Per-tenant SEO machine assets (SLO-89): sitemap.xml, robots.txt and a
 * GD-rendered branded Open Graph image. All three are tenant-scoped via the
 * bound tenant (BelongsToTenant global scope). These are crawler/share assets,
 * not localised UI, so no i18n. robots.txt sits outside ensure.tenant.active so
 * a suspended tenant can still be told `Disallow: /` (see routes/tenant.php).
 */
class SeoController extends Controller
{
    /**
     * XML sitemap: the public homepage, the booking entry, and one deep link per
     * active service. Tenant-scoped and N+1-free (a single service query).
     */
    public function sitemap(): Response
    {
        $urls = [
            ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => url('/book'), 'priority' => '0.8', 'changefreq' => 'weekly'],
        ];

        $services = Service::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id']);

        foreach ($services as $service) {
            $urls[] = [
                'loc' => url('/book?service='.$service->id),
                'priority' => '0.7',
                'changefreq' => 'weekly',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url>'."\n";
            $xml .= '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'."\n";
            $xml .= '    <changefreq>'.$url['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$url['priority'].'</priority>'."\n";
            $xml .= '  </url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * robots.txt: a suspended/archived tenant is fully disallowed; an operational
     * one allows the public surface, hides the admin/members areas and points at
     * the sitemap.
     */
    public function robots(TenantManager $tenants): Response
    {
        $tenant = $tenants->current();

        if ($tenant === null || ! $tenant->status->isOperational()) {
            return response("User-agent: *\nDisallow: /\n", 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $body = "User-agent: *\n"
            ."Allow: /\n"
            ."Disallow: /my/\n"
            ."Disallow: /dashboard\n"
            ."Disallow: /settings\n"
            .'Sitemap: '.url('/sitemap.xml')."\n";

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * Streams the cached, branded OG PNG from the public disk (lazily generated on
     * first hit). Cache-Control lets crawlers/CDNs hold it; the homepage busts a
     * rebrand via the ?v cacheKey.
     */
    public function ogImage(OgImageGenerator $generator, TenantManager $tenants): BinaryFileResponse
    {
        $tenant = $tenants->current();
        abort_if($tenant === null, 404);

        $path = $generator->pathForTenant($tenant);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
