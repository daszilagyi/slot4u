<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Illuminate\Http\Request;

/**
 * Everything this page measures, resolved once (SLO-172, SLO-56).
 *
 * Two measurements can never both apply — the platform's own runs on the central
 * marketing host, the tenant's on a tenant host — but the root Blade and the CSP
 * both need one object to ask, and it must be the SAME object. A policy built
 * from a second, independently computed answer is the SLO-150 failure mode: a
 * page that embeds a script the header then blocks, or permits an origin no page
 * ever loaded.
 *
 * Scoped in AppServiceProvider, and resolved lazily on purpose. The tenant is
 * bound by the `identify.tenant` ROUTE middleware, which runs well after the
 * global SecurityHeaders middleware starts — but the Blade renders inside that
 * pipeline, and the CSP is written after it returns, so by the time either one
 * asks, the tenant is there.
 */
final class PageAnalytics
{
    public function __construct(
        public readonly PlatformAnalytics $platform,
        public readonly TenantAnalytics $tenant,
    ) {}

    public static function forRequest(Request $request): self
    {
        return new self(
            PlatformAnalytics::forRequest($request),
            TenantAnalytics::forRequest($request),
        );
    }

    /** Nothing measured — dev, CI, and any page nobody consented to. */
    public static function none(): self
    {
        return new self(PlatformAnalytics::disabled(), TenantAnalytics::none());
    }

    /**
     * @return array<string, list<string>>
     */
    public function cspOrigins(): array
    {
        return AnalyticsOrigins::merge(
            $this->platform->cspOrigins(),
            $this->tenant->cspOrigins(),
        );
    }
}
