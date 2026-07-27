<?php

declare(strict_types=1);

namespace App\Services\Domain;

/**
 * DNS lookups through the host resolver (SLO-42).
 *
 * `dns_get_record()` emits a warning and returns false on NXDOMAIN or a timed
 * out resolver; both are ordinary outcomes here (the tenant simply has not
 * added the record yet), so they are swallowed into an empty result rather than
 * surfaced as errors.
 */
class SystemDnsResolver implements DnsResolver
{
    public function txt(string $name): array
    {
        $values = [];

        foreach ($this->lookup($name, DNS_TXT) as $record) {
            // Long TXT values arrive split into 255-byte chunks in `entries`;
            // `txt` is the already-joined form when PHP provides it.
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            } elseif (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', array_map(strval(...), $record['entries']));
            }
        }

        return $values;
    }

    public function cname(string $name): ?string
    {
        foreach ($this->lookup($name, DNS_CNAME) as $record) {
            if (isset($record['target']) && is_string($record['target'])) {
                return rtrim(mb_strtolower($record['target'], 'UTF-8'), '.');
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lookup(string $name, int $type): array
    {
        $records = @dns_get_record($name, $type);

        return $records === false ? [] : $records;
    }
}
