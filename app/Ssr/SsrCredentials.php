<?php

declare(strict_types=1);

namespace App\Ssr;

use Psr\Http\Message\RequestInterface;

/**
 * Proves to the SSR renderer that a render request came from us (SLO-212).
 *
 * ⚠️ Why a global HTTP middleware rather than a custom Gateway: Inertia's
 * `HttpGateway::dispatch()` calls `Http::post($url, $page)` with no hook for
 * headers, and the method is forty lines of failure handling, health checks and
 * hot-mode branching. Subclassing it to change one header would mean copying all
 * of that and then owning it through every Inertia upgrade — the kind of fork
 * that rots silently. The middleware is narrow instead: it attaches the header
 * to requests aimed at the renderer and leaves every other outgoing call alone.
 *
 * The renderer is mounted inside our own public site (`/_ssr` under Passenger),
 * so without this anyone on the internet could POST a page object and have the
 * server render arbitrary props into HTML. With it, an unauthorised caller gets
 * a 404 — the renderer does not even admit it exists.
 */
final class SsrCredentials
{
    public const HEADER = 'X-SSR-Secret';

    /**
     * Attach the secret when, and only when, this request is going to the
     * renderer.
     *
     * ⚠️ Compared component by component, NOT as a string prefix. A string
     * prefix looks obviously correct and is not: `http://ssr.test:13714@evil.example/`
     * starts with the configured base URL and points at someone else's server —
     * the userinfo field is a valid part of a URL, and a prefix match hands them
     * the secret. A test sends exactly that.
     */
    public static function attach(RequestInterface $request): RequestInterface
    {
        $secret = self::secret();
        $base = parse_url(self::base());

        if ($secret === '' || ! is_array($base) || ! isset($base['host'])) {
            return $request;
        }

        $uri = $request->getUri();

        $matches = $uri->getScheme() === ($base['scheme'] ?? $uri->getScheme())
            && $uri->getHost() === $base['host']
            && $uri->getPort() === ($base['port'] ?? null)
            && str_starts_with($uri->getPath(), (string) ($base['path'] ?? ''));

        return $matches ? $request->withHeader(self::HEADER, $secret) : $request;
    }

    public static function secret(): string
    {
        return trim((string) config('inertia.ssr.shared_secret', ''));
    }

    private static function base(): string
    {
        return rtrim((string) config('inertia.ssr.url', ''), '/');
    }
}
