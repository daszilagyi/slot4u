<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Merges per-directive origin lists (SLO-56).
 *
 * Two vendors on one page mean two `script`/`connect`/`img` lists that have to
 * become one, without repeating an origin that both need. Small enough to inline
 * — and inlined in two places it would be the kind of duplicate that drifts.
 */
final class AnalyticsOrigins
{
    /**
     * @param  array<string, list<string>|array<int, string>>  ...$sets
     * @return array<string, list<string>>
     */
    public static function merge(array ...$sets): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $directive => $origins) {
                foreach ((array) $origins as $origin) {
                    $origin = trim((string) $origin);

                    if ($origin !== '' && ! in_array($origin, $merged[$directive] ?? [], true)) {
                        $merged[$directive][] = $origin;
                    }
                }
            }
        }

        return $merged;
    }
}
