<?php

namespace App\Http\Middleware;

use App\Support\ContentSecurityPolicy;
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
        $response->headers->set('X-Frame-Options', (string) ($headers['frame_options'] ?? 'DENY'));
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
            extra: (array) config('security.csp.extra'),
        ))->build();
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
     * The realtime origin the browser opens (Reverb, SLO-117). Without it the
     * live booking feed would be blocked by connect-src on every page.
     */
    private function websocketOrigin(): ?string
    {
        /** @var array<string, mixed> $options */
        $options = (array) config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $scheme = ($options['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $port = $options['port'] ?? null;

        return $scheme.'://'.$host.($port !== null && $port !== '' ? ':'.$port : '');
    }
}
