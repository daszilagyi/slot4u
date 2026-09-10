<?php

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
                // The commit is the verifiable half of "which release is this":
                // a ref name matches even when an older commit is serving.
                'commit' => config('deploy.commit'),
                'environment' => app()->environment(),
                'config_cached' => app()->configurationIsCached(),
                'pending_migrations' => $this->pendingMigrations(),
                // Whether this release believes it renders on the server, and
                // whether the renderer is actually answering (SLO-212). The two
                // are reported separately on purpose: "we turned SSR on" and
                // "SSR is working" are different facts, and the failure that hid
                // for months was exactly the gap between them.
                'ssr_enabled' => (bool) config('inertia.ssr.enabled'),
                'ssr_healthy' => $this->ssrHealthy(),
            ])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Whether the SSR renderer answers, or null when we did not ask because
     * nothing would call it anyway.
     *
     * Null rather than false when SSR is off: "not asked" and "asked and it did
     * not answer" must not look the same to the smoke test, or turning SSR off
     * would read as a broken renderer and a broken renderer would read as a
     * deliberate choice.
     */
    private function ssrHealthy(): ?bool
    {
        if (! config('inertia.ssr.enabled')) {
            return null;
        }

        $url = rtrim((string) config('inertia.ssr.url'), '/');

        if ($url === '') {
            return false;
        }

        try {
            // Our own call rather than the gateway's isHealthy(), for the
            // timeout: that one uses the client default (30s), and this endpoint
            // is what a deploy waits on. A renderer that hangs should read as
            // unhealthy in seconds, not stall the check that exists to catch it.
            $response = Http::timeout(5)->get($url.'/health');

            // ⚠️ The BODY has to say so, not just the status code, and
            // production is what proved it. The renderer mounted at `/_ssr`
            // answered `{"status":"NOT_FOUND"}` — with HTTP **200** — to every
            // single path, because Passenger does not strip the mount prefix and
            // Inertia's stock server dispatches on the whole URL. A renderer
            // that renders nothing at all, reported healthy by a status check.
            // That is the SLO-212 failure wearing the check's own uniform.
            return $response->successful() && $response->json('status') === 'OK';
        } catch (Throwable) {
            return false;
        }
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
