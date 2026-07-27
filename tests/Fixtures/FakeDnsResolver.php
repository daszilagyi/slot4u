<?php

namespace Tests\Fixtures;

use App\Services\Domain\DnsResolver;

/**
 * In-memory DNS for the custom-domain tests (SLO-42). Domain verification is
 * the one thing in the app whose answer comes from outside, so the tests drive
 * the zone directly instead of depending on a live one.
 */
class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $txt = [];

    /** @var array<string, string> */
    public array $cname = [];

    public function txt(string $name): array
    {
        return $this->txt[$name] ?? [];
    }

    public function cname(string $name): ?string
    {
        return $this->cname[$name] ?? null;
    }

    /**
     * @param  list<string>|string  $values
     */
    public function setTxt(string $name, array|string $values): void
    {
        $this->txt[$name] = is_array($values) ? $values : [$values];
    }
}
