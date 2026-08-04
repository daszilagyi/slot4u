<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Services\Feature\FeatureResolver;
use App\Tenancy\CustomDomainResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the tenant surface on a tenant's own hostname (SLO-42).
 *
 * Runs as GLOBAL middleware, i.e. before routing, because the route table is
 * keyed on the `{tenant}.{central}` domain pattern. Rather than registering a
 * second copy of routes/tenant.php (which would duplicate every route name and
 * break URL generation), a verified custom host is rewritten to the tenant's
 * canonical subdomain for the purposes of route matching only. Everything
 * downstream — IdentifyTenant, the tenant middleware chain, policies — then
 * works unchanged, with no second code path to keep in sync.
 *
 * The visitor must not notice the rewrite, so the URL generator root is pinned
 * to the original scheme+host first: `url('/book')` on `booking.acme.hu` keeps
 * returning `https://booking.acme.hu/book`.
 *
 * A host that is unknown, unverified, or whose tenant no longer has
 * `feature_custom_domain` is left alone: it then matches no route group at all
 * and falls through to a 404, which is the acceptance criterion — an
 * unverified domain serves nothing, and the tenant's subdomain is untouched.
 *
 * @see CustomDomainResolver
 */
class ResolveCustomDomain
{
    public const ATTRIBUTE = 'tenant_custom_domain';

    public function __construct(
        private readonly CustomDomainResolver $resolver,
        private readonly FeatureResolver $features,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $visitorHost = $this->visitorHost($request);

        $domain = $this->resolver->resolve($visitorHost);

        if ($domain === null) {
            return $next($request);
        }

        $tenant = $domain->tenant;

        if (! $this->features->enabled($tenant, Feature::CustomDomain)) {
            return $next($request);
        }

        // Absolute URLs (canonical tag, OG, sitemap, redirects) must stay on the
        // host the VISITOR used — which is not necessarily the Host header, see
        // visitorHost() — so pin the root before anything is rewritten.
        URL::forceRootUrl($request->getScheme().'://'.$visitorHost);

        // SESSION_DOMAIN is `.{central}` so one session spans the tenant
        // subdomains. A custom domain is outside that scope, so the browser
        // would drop the cookie entirely: no session, no CSRF token, every POST
        // 419. Bind the cookie to this exact host instead. Runs before
        // StartSession (global middleware), and the separate session per host
        // is the honest outcome — they are different origins.
        config(['session.domain' => null]);

        $request->attributes->set(self::ATTRIBUTE, $domain);

        $canonical = $tenant->slug.'.'.config('tenancy.central_domain');

        // Both the header bag and the server bag: Symfony's getHost() reads the
        // header, but anything reconstructing from $_SERVER must agree with it.
        $request->headers->set('HOST', $canonical);
        $request->server->set('HTTP_HOST', $canonical);

        return $next($request);
    }

    /**
     * The hostname the visitor actually typed.
     *
     * Normally the Host header. But Cloudflare for SaaS forwards the visitor's
     * own hostname as Host, and the shared-hosting vhost only answers for
     * `*.{central}` — so an Origin Rule rewrites Host to the fallback origin
     * and a Transform Rule carries the real name in a private header instead
     * (SLO-135, docs/01). Where that is in play, the header is the truth.
     *
     * Only where the edge that set it can be trusted ({@see edgeIsTrusted}):
     * the header is otherwise trivially forgeable, and believing it would let
     * anyone claim any tenant's domain by setting it by hand.
     */
    private function visitorHost(Request $request): string
    {
        $header = (string) config('tenancy.original_host_header');

        if ($header === '' || ! $this->edgeIsTrusted($request)) {
            return $request->getHost();
        }

        $forwarded = $request->headers->get($header);

        return is_string($forwarded) && $forwarded !== '' ? $forwarded : $request->getHost();
    }

    /**
     * Whether the forwarded host may be believed (SLO-140).
     *
     * A configured shared secret wins over the peer address, because the peer
     * address is not always there to judge: on the production shared host,
     * Apache rewrites REMOTE_ADDR to the real visitor before PHP sees it, so no
     * request ever looks like it came from Cloudflare and custom domains 404ed.
     * The fix is not to trust every proxy — that would also mean believing
     * X-Forwarded-For from anyone, i.e. client-IP spoofing in the audit log and
     * the rate limiter — but to make trust something the edge can *prove*.
     *
     * Where no secret is configured (dev, CI, a host that does expose the
     * proxy) the peer address remains the test, so nothing changes for them.
     */
    private function edgeIsTrusted(Request $request): bool
    {
        $secret = config('tenancy.original_host_secret');

        if (! is_string($secret) || $secret === '') {
            return $request->isFromTrustedProxy();
        }

        $presented = $request->headers->get((string) config('tenancy.original_host_secret_header'));

        // hash_equals, not ===: the comparison runs on every request to the
        // fallback origin, and a length-varying one leaks the secret over time.
        return is_string($presented) && hash_equals($secret, $presented);
    }
}
