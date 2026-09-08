<?php

namespace App\Http\Middleware;

use App\Support\Analytics\PageAnalytics;
use App\Support\ContentSecurityPolicy;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security response headers (SLO-145, docs/01). Until this the app sent none at
 * all — no CSP, no frame protection, no sniffing protection.
 *
 * The nonce is generated *before* the response is built, because the root Blade
 * and Laravel's Vite helper stamp it onto their script tags while rendering; the
 * header written afterwards therefore names the very nonce the page used.
 *
 * HSTS is sent only over HTTPS. A max-age pinned from a plain-http dev host would
 * make that host unreachable over http for a year, which is a self-inflicted
 * outage rather than a protection.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $headers = (array) config('security.headers');

        // Applies to every response, not just documents: a JSON or PDF response
        // that a browser sniffs into HTML is exactly the case this prevents.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // ⚠️ The one surface allowed inside a frame: a demo tenant, embedded by
        // the marketing site (SLO-192).
        //
        // `X-Frame-Options` cannot say "this one origin" — `ALLOW-FROM` is dead
        // in every current browser — so for a demo tenant the header is OMITTED
        // and `frame-ancestors` below carries the rule instead. That is the
        // modern mechanism and the more precise one; in a browser too old to
        // honour CSP the page would be framable by anyone, which for a tenant
        // holding nothing but fixtures (docs/20 §3.1) is a trade worth naming
        // rather than a hole worth closing.
        //
        // Every other tenant, and every non-tenant surface, keeps DENY.
        if (! $this->isEmbeddableDemo()) {
            $response->headers->set('X-Frame-Options', (string) ($headers['frame_options'] ?? 'DENY'));
        }
        $response->headers->set('Referrer-Policy', (string) ($headers['referrer_policy'] ?? 'strict-origin-when-cross-origin'));
        $response->headers->set('Permissions-Policy', (string) ($headers['permissions_policy'] ?? ''));

        if ($request->isSecure()) {
            $maxAge = (int) ($headers['hsts_max_age'] ?? 31536000);
            $response->headers->set('Strict-Transport-Security', 'max-age='.$maxAge.'; includeSubDomains');
        }

        if (config('security.csp.enabled') === true) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $hot = Vite::isRunningHot();

        return (new ContentSecurityPolicy(
            nonce: $nonce,
            hot: $hot,
            devServer: $hot ? $this->devServerOrigin() : null,
            websocket: $this->websocketOrigin(),
            errorReporting: $this->errorReportingOrigin(),
            extra: $this->cspExtra(),
            // Measurement origins, from the very object the root Blade asked
            // whether to emit the tag (SLO-172) — so the policy can never permit
            // Google on a page that did not load it, nor block it on one that
            // did. Resolved after $next() has run, which is when the request's
            // consent and host have already decided the answer.
            analytics: app(PageAnalytics::class)->cspOrigins(),
        ))->build();
    }

    /**
     * Whether THIS request is for a demo tenant's own pages.
     *
     * ⚠️ Read from the resolved tenant, not from the host string. A host can be
     * spoofed by a proxy header; `is_demo` is a column, set on a model the
     * tenancy middleware already looked up, and it is the same flag every other
     * demo guardrail keys on (docs/20 §3.1).
     */
    private function isEmbeddableDemo(): bool
    {
        return app(TenantManager::class)->current()?->is_demo === true;
    }

    /**
     * CSP additions for this request.
     *
     * @return array{script?: string, connect?: string, img?: string, frame?: string}
     */
    private function cspExtra(): array
    {
        /** @var array{script?: string, connect?: string, img?: string, frame?: string} $extra */
        $extra = (array) config('security.csp.extra');

        if (! $this->isEmbeddableDemo()) {
            return $extra;
        }

        // The marketing site, and nothing else. `'self'` keeps the demo able to
        // frame its own pages; the central domain is what the "try it live"
        // section is served from.
        $scheme = request()->isSecure() ? 'https' : 'http';
        $central = $scheme.'://'.config('tenancy.central_domain');

        $extra['frame'] = trim(($extra['frame'] ?? '').' \'self\' '.$central);

        return $extra;
    }

    /**
     * Where the browser sends JavaScript errors (SLO-153).
     *
     * Derived from the DSN rather than configured separately, so enabling error
     * reporting cannot leave the policy behind — the SLO-150 failure mode, where
     * a correct-looking CSP silently killed the live feed.
     *
     * Falls back to the backend DSN: the two Sentry projects live in the same
     * organisation and therefore share one ingest host, so a host that only set
     * the server DSN still gets a policy that would admit browser reports.
     */
    private function errorReportingOrigin(): ?string
    {
        $dsn = (string) config('monitoring.browser_dsn');

        if ($dsn === '') {
            $dsn = (string) config('sentry.dsn');
        }

        if ($dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Origin of the running Vite dev server, from the hot file it writes. Read
     * rather than configured, so it follows whatever port the dev server picked.
     */
    private function devServerOrigin(): ?string
    {
        $path = public_path('hot');

        if (! is_file($path)) {
            return null;
        }

        $url = trim((string) file_get_contents($path));
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        return ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * The realtime origin the *browser* opens (SLO-117). Without it the live
     * booking feed is blocked by connect-src on every page that listens.
     *
     * Read off the **active** broadcast connection, not a hardcoded one: dev runs
     * Reverb while production runs hosted Pusher, so naming a driver here would
     * silently produce a policy that blocks the socket in the other environment
     * (SLO-150 — this shipped that way and was caught before it reached prod).
     *
     * ⚠️ Pusher's configured `host` is `api-{cluster}.pusher.com`, which is the
     * server-side REST endpoint. The browser connects to `ws-{cluster}` instead,
     * so the client host has to be derived, not copied.
     */
    private function websocketOrigin(): ?string
    {
        $connection = (string) config('broadcasting.default');
        /** @var array<string, mixed> $options */
        $options = (array) config('broadcasting.connections.'.$connection.'.options', []);

        return match ((string) config('broadcasting.connections.'.$connection.'.driver')) {
            'reverb' => $this->originFrom($options),
            'pusher' => $this->pusherClientOrigin($options),
            // log / null / anything else opens no socket.
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function originFrom(array $options): ?string
    {
        $host = $options['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $scheme = ($options['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $port = $options['port'] ?? null;

        return $scheme.'://'.$host.($port !== null && $port !== '' ? ':'.$port : '');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function pusherClientOrigin(array $options): ?string
    {
        $cluster = $options['cluster'] ?? null;

        if (! is_string($cluster) || $cluster === '') {
            return null;
        }

        return 'wss://ws-'.$cluster.'.pusher.com';
    }
}
