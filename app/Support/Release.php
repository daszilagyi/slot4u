<?php

namespace App\Support;

/**
 * The release currently deployed to this host (SLO-152).
 *
 * `deploy/deploy.sh` writes the gitignored `.release` file before it rebuilds
 * the config cache, so the value is baked into cached config and costs no disk
 * read per request. Two lines: the ref that was asked for, and the commit it
 * resolved to.
 *
 * The commit is the one that can be trusted. A ref name is a label that moves —
 * a deploy of `main` that silently shipped a months-old commit still reported
 * "main", and only the sha exposed it (SLO-158).
 *
 * Lives in its own class because two configs need the same answer — the deploy
 * health endpoint and Sentry's release tag (SLO-153) — and one of them reading
 * the other's config would make the app depend on config files loading in
 * alphabetical order.
 */
final class Release
{
    /**
     * The deployed ref (a tag, usually). Null means "nothing deployed this
     * tree", the normal state locally and in CI.
     */
    public static function current(): ?string
    {
        return self::line(0);
    }

    /**
     * The commit that ref resolved to at deploy time.
     */
    public static function commit(): ?string
    {
        return self::line(1);
    }

    private static function line(int $index): ?string
    {
        $file = base_path('.release');

        if (! is_readable($file)) {
            return null;
        }

        $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];
        $value = trim((string) ($lines[$index] ?? ''));

        return $value === '' ? null : $value;
    }
}
