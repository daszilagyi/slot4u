<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Support\CookieConsent;
use Illuminate\Http\Request;

/**
 * Whether slot4u's own GA4 tag loads on THIS request, and what the policy has to
 * allow if it does (SLO-172, docs/08).
 *
 * Three conditions, all decided on the server before a byte goes out:
 *
 *  1. A measurement id is configured. Absent in dev and CI, so neither reports.
 *  2. The visitor granted the `analytics` category (SLO-165). Gating in the
 *     browser would be theatre — by the time JavaScript could decide, gtag.js is
 *     already downloaded and the request to Google already made.
 *  3. The request is for the central marketing host. Never a tenant subdomain:
 *     there the tenant is the controller and slot4u the processor (docs/19 §2),
 *     and a platform-owned property collecting that traffic would be slot4u
 *     using someone else's visitors for its own purposes.
 *
 * Resolved once per request (scoped in AppServiceProvider) because two places
 * need the same answer and must not be able to disagree:
 * the root Blade decides whether to emit the tag, and the CSP decides whether
 * Google is a permitted origin. A policy built from a different answer than the
 * markup is the SLO-150 failure mode — a page that looks correct and silently
 * blocks the thing it just embedded.
 */
final class PlatformAnalytics
{
    /**
     * GA4 counts visitors, so it answers to `analytics`. A constant rather than
     * a config key: the tenant side is configurable because a tenant's vendors
     * are its own business (SLO-56), but slot4u's own property is ours, and a
     * setting that could point it at `necessary` is a setting that could turn
     * the consent gate off by editing an .env.
     */
    private const CATEGORY = 'analytics';

    private function __construct(
        /** Null whenever the tag must not load, for any of the three reasons. */
        public readonly ?string $measurementId,
    ) {}

    public static function forRequest(Request $request): self
    {
        if (self::gatedCategories($request) === []) {
            return new self(null);
        }

        if (! CookieConsent::fromRequest($request)->allows(self::CATEGORY)) {
            return new self(null);
        }

        return new self(self::configuredId());
    }

    /**
     * The consent categories whose answer can change what this request loads —
     * conditions 1 and 3 above, with the visitor's answer (2) left out (SLO-218).
     *
     * Empty means the banner has nothing to ask on slot4u's behalf here: no id
     * configured (every dev laptop, CI, and any host that is not production) or
     * a tenant subdomain, where the platform never measures at all (§11.1.2).
     *
     * Derived from the same conditions {@see forRequest()} applies rather than
     * restated, so "would we ask?" and "would we emit?" cannot disagree. A
     * second copy of the rules is how a page ends up asking for permission to
     * do something it was never going to do — or, worse, the other way round.
     *
     * @return list<string>
     */
    public static function gatedCategories(Request $request): array
    {
        if (self::configuredId() === '') {
            return [];
        }

        if (! self::isCentralHost($request)) {
            return [];
        }

        return [self::CATEGORY];
    }

    private static function configuredId(): string
    {
        return trim((string) config('analytics.platform.ga4_measurement_id'));
    }

    /** Nothing configured, nothing consented to, or the wrong host. */
    public static function disabled(): self
    {
        return new self(null);
    }

    public function loads(): bool
    {
        return $this->measurementId !== null;
    }

    /**
     * Extra CSP sources this request needs — empty unless the tag is actually
     * emitted, so declining analytics also narrows the policy back down.
     *
     * @return array<string, list<string>>
     */
    public function cspOrigins(): array
    {
        if (! $this->loads()) {
            return [];
        }

        return AnalyticsOrigins::merge((array) config('analytics.origins.ga4', []));
    }

    /**
     * The apex marketing host, and only it.
     *
     * `www` is excluded on purpose rather than by oversight: it redirects to the
     * apex at the edge (SLO-139), so a tag there would only ever be emitted on a
     * response nobody renders. A custom tenant domain cannot reach this either —
     * ResolveCustomDomain has already rewritten it to `{slug}.{central}`.
     */
    private static function isCentralHost(Request $request): bool
    {
        return $request->getHost() === (string) config('tenancy.central_domain');
    }
}
