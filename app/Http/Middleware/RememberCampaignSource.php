<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Marketing\CampaignAttribution;
use App\Settings\TenantSignupSource;
use App\Support\MarketingSurface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes which campaign a visitor arrived on, so the sign-up they may make later
 * in the same visit can be attributed to it (SLO-210).
 *
 * ⚠️ **Only on the central marketing surface**, and that gate is the point
 * rather than an optimisation. `/register` is a Fortify route with no domain
 * constraint, so `utm_*` can arrive on ANY host — including a tenant's own
 * booking page, where the campaign in the URL is the TENANT's, bought with the
 * tenant's money, aimed at the tenant's customers. Harvesting it into slot4u's
 * records would be the platform helping itself to data it processes for someone
 * else: the same controller boundary as docs/19 §11.1.2 and §11.6, which we
 * have now got wrong once already.
 *
 * {@see MarketingSurface} answers "is this ours" by ROUTE NAME, which is what
 * keeps a tenant's `/` (route `tenant.home`) out even though the path matches.
 */
class RememberCampaignSource
{
    public function __construct(
        private readonly CampaignAttribution $attribution,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // GET only: the marketing pages are read, and a campaign arriving on a
        // POST would be a form replaying a query string rather than a person
        // following a link.
        if ($request->isMethod('GET') && MarketingSurface::matches($request)) {
            $this->attribution->remember(TenantSignupSource::fromRequest($request));
        }

        return $next($request);
    }
}
