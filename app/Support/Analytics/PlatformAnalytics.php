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
    private function __construct(
        /** Null whenever the tag must not load, for any of the three reasons. */
        public readonly ?string $measurementId,
    ) {}

    public static function forRequest(Request $request): self
    {
        $id = trim((string) config('analytics.platform.ga4_measurement_id'));

        if ($id === '') {
            return new self(null);
        }

        if (! self::isCentralHost($request)) {
            return new self(null);
        }

        if (! CookieConsent::fromRequest($request)->allows('analytics')) {
            return new self(null);
        }

        return new self($id);
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
     * @return array{script?: list<string>, connect?: list<string>, img?: list<string>}
     */
    public function cspOrigins(): array
    {
        if (! $this->loads()) {
            return [];
        }

        /** @var array{script?: list<string>, connect?: list<string>, img?: list<string>} $origins */
        $origins = (array) config('analytics.origins.ga4', []);

        return $origins;
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
