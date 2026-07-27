<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Normalisation and validation for custom tenant hostnames (SLO-42).
 *
 * Everything is stored and compared in the exact shape a Host header arrives
 * in: lowercase, ASCII (punycode), no scheme, no port, no trailing dot. Doing
 * this in one place is what makes the unique index on `tenant_domains.domain`
 * an actual guarantee — otherwise `Acme.HU`, `acme.hu.` and `xn--` variants of
 * the same name would all be separate rows claiming the same host.
 */
final class DomainName
{
    /**
     * Reduce user input to a canonical hostname, or null if it cannot be one.
     */
    public static function normalize(string $input): ?string
    {
        $value = trim($input);

        // Accept a pasted URL and keep only its host.
        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);

            if (! is_string($host)) {
                return null;
            }

            $value = $host;
        }

        // Drop a path, a port and the DNS root dot.
        $value = explode('/', $value, 2)[0];
        $value = explode(':', $value, 2)[0];
        $value = rtrim($value, '.');
        $value = mb_strtolower($value, 'UTF-8');

        if ($value === '') {
            return null;
        }

        // IDN → punycode, so the stored value matches the Host header.
        if (! preg_match('/^[\x20-\x7E]*$/', $value)) {
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                return null;
            }

            $value = $ascii;
        }

        return self::isValid($value) ? $value : null;
    }

    /**
     * A registrable, multi-label ASCII hostname — never a bare TLD, an IP, or
     * anything with a label that DNS would reject.
     */
    public static function isValid(string $host): bool
    {
        if (strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }

        // An IPv4 literal is a host but never a domain a tenant can prove.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label)) {
                return false;
            }
        }

        // The public suffix itself must be alphabetic (`acme.hu`, not `acme.1`).
        $tld = substr($host, (int) strrpos($host, '.') + 1);

        return preg_match('/^[a-z]{2,63}$/', $tld) === 1;
    }

    /**
     * Whether the host belongs to slot4u's own domain space. Those can never be
     * added as custom domains — the tenant subdomain already serves them, and
     * accepting one would let a tenant shadow another tenant's canonical host.
     */
    public static function isCentral(string $host): bool
    {
        $central = (string) config('tenancy.central_domain');

        return $host === $central || str_ends_with($host, '.'.$central);
    }
}
