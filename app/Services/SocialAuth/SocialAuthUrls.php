<?php

declare(strict_types=1);

namespace App\Services\SocialAuth;

use App\Enums\SocialProvider;
use Illuminate\Http\Request;

/**
 * The URLs a social sign-in is addressed by (SLO-251, docs/28).
 */
final class SocialAuthUrls
{
    /**
     * The provider callback — ALWAYS on the central domain, whichever host the
     * person started on. Google and Meta accept only exact redirect URIs, so a
     * wildcard subdomain or a tenant's own domain cannot be registered with
     * them; this is the one URI there is.
     */
    public static function callback(SocialProvider $provider): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return sprintf('%s://%s/auth/%s/callback', $scheme, config('tenancy.central_domain'), $provider->value);
    }

    /**
     * Which providers to offer on this host. None on the admin panel: a
     * super-admin signs in with a password and a second factor, nothing else.
     *
     * @return list<string>
     */
    public static function offeredOn(Request $request): array
    {
        $adminHost = config('tenancy.admin_subdomain').'.'.config('tenancy.central_domain');

        return $request->getHost() === $adminHost ? [] : SocialProvider::configured();
    }

    /**
     * A return target the flow may carry: a path on the starting host, never a
     * URL. A relative path cannot leave the host the consume step runs on, so
     * there is no allow-list of hosts to get wrong — an open redirect is not a
     * validation failure here, it is unrepresentable.
     */
    public static function safeReturnPath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }

        // `//evil.test` and `/\evil.test` are protocol-relative to a browser.
        if ($value[0] !== '/' || str_starts_with($value, '//') || str_contains($value, '\\')) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        // Back into the sign-in machinery would only loop.
        if (str_starts_with($value, '/auth/')) {
            return null;
        }

        return $value;
    }
}
