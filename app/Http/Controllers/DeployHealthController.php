<?php

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Post-deploy verification endpoint (SLO-152).
 *
 * `/up` already answers "is the app alive". This answers the question a deploy
 * actually needs: *which* release is serving, and did its migrations run. Both
 * are things only the running application can say — an SSH check of the
 * checked-out git ref proves what is on disk, not what PHP is executing.
 *
 * Token-gated with a 404 rather than a 403 (the project's cross-tenant
 * convention): a caller without the secret cannot tell the endpoint exists.
 */
class DeployHealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('deploy.health_token');
        $presented = (string) $request->header('X-Deploy-Token', '');

        // Empty config means no token was ever set: nobody passes, including a
        // caller who sends an empty header.
        abort_if($expected === '' || ! hash_equals($expected, $presented), 404);

        return response()
            ->json([
                'release' => config('deploy.release'),
                'environment' => app()->environment(),
                'config_cached' => app()->configurationIsCached(),
                'pending_migrations' => $this->pendingMigrations(),
            ])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * How many migration files have not run yet, or null when that cannot be
     * determined (database unreachable, migrations table missing).
     *
     * Null is deliberately not folded into 0: the smoke test treats "cannot
     * tell" as a failed deploy, which is what an unreachable database is.
     */
    private function pendingMigrations(): ?int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return null;
            }

            $ran = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles(
                array_merge([database_path('migrations')], $migrator->paths())
            );

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }
}
