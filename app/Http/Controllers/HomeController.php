<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Commission\BuildPublicCommissionTerms;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public marketing landing on the central domain (SLO-50).
 */
class HomeController extends Controller
{
    public function __invoke(BuildPublicCommissionTerms $terms): Response
    {
        return Inertia::render('Welcome', [
            // One indexed, limit-1 query per render. Not cached on purpose: a
            // stale cache here means the page advertises a price the platform
            // no longer charges, which is the one failure mode worth a query.
            'commission' => $terms->build()?->toArray(),

            'demo_url' => $this->demoUrl(),

            // ⚠️ Absolute, because every platform that fetches an OG image
            // fetches it from its own servers — a root-relative path resolves
            // against THEIR host and 404s. Built server-side for the same reason
            // it cannot come from `window.location`: the page is server-rendered
            // (SSR), where there is no window (SLO-170).
            'og_image' => rtrim((string) config('app.url'), '/').'/img/og-image.png',
        ]);
    }

    /**
     * The seeded demo tenant's public booking page, built from configuration
     * rather than written into the copy. Null when no demo slug is configured,
     * so an installation without one renders no demo link instead of a dead
     * first click.
     */
    private function demoUrl(): ?string
    {
        $slug = config('tenancy.demo_slug');

        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        // Same scheme as the site itself: a demo link that drops to http on an
        // https page is blocked as mixed content by every current browser.
        $scheme = Str::before((string) config('app.url'), '://') === 'http' ? 'http' : 'https';

        return $scheme.'://'.$slug.'.'.config('tenancy.central_domain');
    }
}
