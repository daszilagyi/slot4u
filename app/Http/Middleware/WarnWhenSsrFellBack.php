<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Inertia\Ssr\BundleDetector;
use Symfony\Component\HttpFoundation\Response;

/**
 * Says out loud, outside production, when a page went out without being
 * server-rendered (SLO-222).
 *
 * ⚠️ This exists because losing SSR is not an error. Inertia falls back to
 * client-side rendering and says nothing: the page works, the tests pass, the
 * browser looks right — and a crawler gets an empty shell. That silence let
 * SLO-212 live on production for months, and it is the everyday state of the
 * development environment, where the Vite dev server takes over the SSR route
 * and this container cannot reach the address it uses.
 *
 * So the point is not to fix the fallback. It is to make sure nobody can work
 * for a day inside it without knowing.
 *
 * ⚠️ NOT in production. There the deploy smoke test is the guard, and it fails
 * the deploy; a log line per request would be noise on top of a check that
 * already stopped the release.
 */
final class WarnWhenSsrFellBack
{
    /** The marker @inertiajs/react puts on the root element it rendered. */
    private const RENDERED = 'data-server-rendered';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (app()->isProduction() || ! config('inertia.ssr.enabled')) {
            return $response;
        }

        // Only the full-page HTML an Inertia visit produces. An XHR visit
        // carries JSON and is never server-rendered by design, and a redirect
        // or a file download has no markup to miss.
        if ($request->header('X-Inertia') !== null || $response->getStatusCode() !== 200) {
            return $response;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || ! str_contains($content, 'id="app"')) {
            return $response;
        }

        if (str_contains($content, self::RENDERED)) {
            return $response;
        }

        Log::warning('[ssr] '.$request->getPathInfo().' was NOT server-rendered — a crawler '
            .'would see an empty shell here. '.$this->reason());

        return $response;
    }

    /**
     * Why the renderer did not produce this page.
     *
     * Three different things to go and do, so they must not arrive as one
     * message. The order matches Inertia's own: hot mode wins over the bundle,
     * and the bundle is checked before anything is dispatched.
     */
    private function reason(): string
    {
        if (Vite::isRunningHot()) {
            return 'Vite is running hot, so Inertia sends the render to the dev server '
                .'('.rtrim((string) @file_get_contents(Vite::hotFile())).'/__inertia_ssr) '
                .'instead of INERTIA_SSR_URL — and from this container that address is not '
                .'the Vite container. This is SLO-222; docs/01 has how to see real SSR locally.';
        }

        if (config('inertia.ssr.ensure_bundle_exists', true) && app(BundleDetector::class)->detect() === null) {
            return 'There is no SSR bundle where Inertia looks for one (bootstrap/ssr/ssr.js). '
                .'Run `npm run build`, or set INERTIA_SSR_ENSURE_BUNDLE_EXISTS=false if the '
                .'bundle lives elsewhere, as it does in production.';
        }

        return 'The renderer at '.config('inertia.ssr.url').' did not answer, or answered with '
            .'a failure. Check that it is running (`docker compose ps ssr`) and that it is '
            .'serving the current bundle (`docker compose restart ssr` after a build).';
    }
}
