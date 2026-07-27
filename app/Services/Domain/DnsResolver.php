<?php

declare(strict_types=1);

namespace App\Services\Domain;

/**
 * The seam between domain verification and the outside world (SLO-42).
 *
 * Verification is the one place in the app whose answer comes from public DNS
 * rather than our own database, so it sits behind an interface: tests swap in a
 * fake instead of depending on a live zone, and a future move to a resolver
 * with DNSSEC validation or a hosted DNS API is a binding change.
 */
interface DnsResolver
{
    /**
     * TXT record values for a name. An empty array covers both "no such record"
     * and "lookup failed" — the caller treats them identically (not verified).
     *
     * @return list<string>
     */
    public function txt(string $name): array;

    /**
     * The CNAME target of a name, or null when it has none. Informational: a
     * domain may legitimately point at us with an A/ALIAS record instead.
     */
    public function cname(string $name): ?string;
}
