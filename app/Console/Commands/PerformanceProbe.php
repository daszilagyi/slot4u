<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantPublicUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Measures how long the public booking surface takes to answer (SLO-176,
 * docs/17 §10).
 *
 * ⚠️ Read-only, and safe to run against production — that is the point. The
 * installation has no staging (SLO-156), so the only place a number means
 * anything is the host that serves real traffic, on real data. Every route it
 * probes is a GET the public already issues; nothing is written.
 *
 * It dispatches through the HTTP kernel IN-PROCESS rather than over the network.
 * That is deliberate: on shared hosting there is no load generator to install,
 * and a curl from a laptop measures the internet more than it measures us. What
 * comes out is the application's own time — routing, middleware, queries,
 * rendering — which is the part an audit can act on.
 *
 * The trade is stated rather than hidden: nginx, PHP-FPM process pickup and TLS
 * are NOT in these numbers, and neither is a cold opcache. Treat the result as a
 * floor. The query counts have no such caveat and are usually the more
 * actionable half — a route that got slower almost always got chattier first.
 */
class PerformanceProbe extends Command
{
    protected $signature = 'perf:probe
        {--tenant= : Tenant slug to probe (defaults to the configured demo tenant, then the first active one)}
        {--iterations=30 : Requests per route; the first is discarded as a warm-up}
        {--queries : Also list the slowest queries of each route — the answer to "which one"}';

    protected $description = 'Time the public booking routes on this host (read-only)';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        if (! $tenant instanceof Tenant) {
            $this->components->error('No active tenant to probe. Pass --tenant=<slug>.');

            return self::FAILURE;
        }

        $iterations = max(2, (int) $this->option('iterations'));
        $base = rtrim(app(TenantPublicUrl::class)->to($tenant, '/'), '/');

        $this->liftPublicRateLimit();

        $this->components->info("Probing {$base} — {$iterations} iterations per route");

        $rows = [];
        $bad = [];

        foreach ($this->routes($tenant) as $label => $path) {
            $row = $this->probe($label, $base.$path, $iterations);
            $rows[] = $row;

            if ($row[6] !== 200) {
                $bad[] = $label.' → HTTP '.$row[6];
            }
        }

        $this->table(
            ['Route', 'p50 (ms)', 'p95 (ms)', 'max (ms)', 'queries', 'db (ms)', 'status'],
            $rows,
        );
        $this->line('');

        // ⚠️ The whole point of this guard: the first run of this command
        // produced a beautiful table of single-digit milliseconds, every one of
        // them the time it takes to render a 429. A probe that reports timings
        // for error responses is worse than no probe — it is a number somebody
        // will quote.
        if ($bad !== []) {
            $this->components->error(
                'Measured a non-200 response — these timings mean nothing: '.implode(', ', $bad)
            );

            return self::FAILURE;
        }

        $this->components->warn(
            'In-process timing: nginx, FPM pickup and TLS are not included. Treat as a floor.'
        );

        return self::SUCCESS;
    }

    /**
     * Take the public throttle off for the duration of the probe.
     *
     * Thirty requests in a second is not a visitor, it is a measurement — and the
     * limiter (60/minute, SLO-147) would answer most of them with a 429 whose
     * timing describes the limiter rather than the page. The limiter itself is
     * not what this command exists to measure, and lifting it in a console
     * process changes nothing about the running site.
     */
    private function liftPublicRateLimit(): void
    {
        foreach (['public', 'seo'] as $name) {
            RateLimiter::for($name, fn (): Limit => Limit::none());
        }
    }

    /**
     * The public paths worth timing: the shop window and the three states of the
     * slot picker, which is the expensive one (AvailabilityService bulk-loads
     * schedules, exceptions and bookings for every day it shows).
     *
     * @return array<string, string>
     */
    private function routes(Tenant $tenant): array
    {
        $routes = [
            'home' => '/',
            'book (no service)' => '/book',
        ];

        $service = Service::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('active', true)
            ->whereIn('booking_mode', ['duration_based', 'resource_rental'])
            ->orderBy('id')
            ->first();

        if ($service === null) {
            $this->components->warn('No slot-based service on this tenant — the picker is not probed.');

            return $routes;
        }

        // Today in the tenant's own calendar: the day the picker opens on, and
        // therefore the one a real visitor actually pays for.
        $today = Carbon::now($tenant->timezone)->toDateString();

        $routes['book (service)'] = '/book?service='.$service->getKey();
        $routes['book (service+day)'] = '/book?service='.$service->getKey().'&date='.$today;

        return $routes;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: int, 5: string, 6: int}
     */
    private function probe(string $label, string $url, int $iterations): array
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);

        $durations = [];
        $queries = 0;
        $dbMs = 0.0;
        $status = 0;

        // Registered once, outside the loop: DB::listen has no way to remove a
        // listener, so re-registering per iteration would stack thirty of them
        // and count every query thirty times.
        $slowest = [];

        DB::listen(function ($query) use (&$queries, &$dbMs, &$slowest): void {
            $queries++;
            $dbMs += (float) $query->time;
            $slowest[] = [(float) $query->time, (string) $query->sql];
        });

        for ($i = 0; $i < $iterations; $i++) {
            // Reset per iteration and reported from the LAST one, so the figures
            // describe a single request rather than the whole run.
            $queries = 0;
            $dbMs = 0.0;
            $slowest = [];

            // A fresh tenant binding per request, exactly as a real one starts.
            // Without this the second iteration would reuse the first's resolved
            // tenant and measure a warmer path than production ever runs.
            app(TenantManager::class)->forget();

            $start = hrtime(true);
            $response = $kernel->handle(Request::create($url, 'GET'));
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            $status = $response->getStatusCode();

            // Discard the warm-up: the first request through a cold container
            // pays for autoloading and config resolution that no later request
            // does, and including it would flatter nothing and mislead everyone.
            if ($i > 0) {
                $durations[] = $elapsed;
            }
        }

        sort($durations);

        if ($this->option('queries')) {
            $this->reportSlowQueries($label, $slowest);
        }

        return [
            $label,
            $this->format($this->percentile($durations, 0.50)),
            $this->format($this->percentile($durations, 0.95)),
            $this->format(end($durations) ?: 0.0),
            $queries,
            // Against the total, this is the column that says WHICH fix applies:
            // a slow query wants an index, and time that is not in the database
            // wants different code. Guessing between the two is how a cache gets
            // built for a problem the cache does not solve.
            $this->format($dbMs),
            $status,
        ];
    }

    /**
     * The three slowest queries of one request, in full.
     *
     * "Twelve queries and 458 ms of database time" narrows the problem to the
     * database; this names the statement. Without it the next step is a guess,
     * and a guess here builds a cache for something an index would have fixed.
     *
     * @param  list<array{0: float, 1: string}>  $queries
     */
    private function reportSlowQueries(string $label, array $queries): void
    {
        usort($queries, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $this->line('');
        $this->components->twoColumnDetail('<fg=yellow>'.$label.'</>', 'slowest queries');

        foreach (array_slice($queries, 0, 3) as [$ms, $sql]) {
            $this->components->twoColumnDetail(
                '  '.Str::limit(preg_replace('/\s+/', ' ', $sql) ?? $sql, 130),
                number_format($ms, 1).' ms',
            );
        }
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        // Nearest-rank. With thirty samples the interpolated variants differ by
        // less than the noise between two runs, and this one is explainable.
        $rank = (int) ceil($p * count($sorted));

        return $sorted[max(0, $rank - 1)];
    }

    private function format(float $ms): string
    {
        return number_format($ms, 1);
    }

    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');

        if (is_string($slug) && $slug !== '') {
            return Tenant::query()->where('slug', $slug)->first();
        }

        $demo = (string) config('tenancy.demo_slug');

        return ($demo !== '' ? Tenant::query()->where('slug', $demo)->first() : null)
            ?? Tenant::query()->orderBy('id')->first();
    }
}
