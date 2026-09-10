<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CookieConsent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deletes the consent decision that used to be shared across every host
 * (SLO-220, docs/19 §11.6).
 *
 * Until this release the decision lived at `Domain=.{central}`, so one answer
 * covered the marketing site and every tenant — different data controllers,
 * one click. The replacement is host-only and under a different name, which
 * fixes new decisions but leaves the old cookie sitting in the browsers of
 * everyone who already answered, for up to a year, still being sent on every
 * request to every one of our hosts.
 *
 * Nothing reads it any more, so it is not a live leak. It is stale data about
 * a person's privacy choices that we no longer have a reason to hold — so we
 * stop holding it, at the first opportunity, rather than waiting out its own
 * expiry.
 *
 * In the web group rather than on one route: the deletion should ride whichever
 * request the visitor happens to make next, on whichever host, and there is no
 * single page everyone passes through.
 *
 * ⚠️ Temporary by construction. Once `consent.lifetime_days` has elapsed since
 * this shipped, no browser can still be carrying the old cookie and this
 * middleware, its config key and its test can go.
 */
class RetireSharedConsentCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $removal = CookieConsent::retireSharedDecision($request);

        // Queued before the response is built, not attached to it afterwards:
        // AddQueuedCookiesToResponse sits earlier in the web group, so it
        // unwinds last and picks this up — including on a redirect, which is
        // what the consent form itself returns.
        if ($removal instanceof SymfonyCookie) {
            Cookie::queue($removal);
        }

        return $next($request);
    }
}
