<?php

namespace App\Support;

/**
 * The release currently deployed to this host (SLO-152).
 *
 * `deploy/deploy.sh` writes the deployed tag into the gitignored `.release` file
 * before it rebuilds the config cache, so the value is baked into cached config
 * and costs no disk read per request.
 *
 * Lives in its own class because two configs need the same answer — the deploy
 * health endpoint and Sentry's release tag (SLO-153) — and one of them reading
 * the other's config would make the app depend on config files loading in
 * alphabetical order.
 */
final class Release
{
    /**
     * Null means "nothing deployed this tree", the normal state locally and in CI.
     */
    public static function current(): ?string
    {
        $file = base_path('.release');

        if (! is_readable($file)) {
            return null;
        }

        $release = trim((string) file_get_contents($file));

        return $release === '' ? null : $release;
    }
}
