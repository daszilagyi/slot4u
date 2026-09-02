<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\PurgeDemoTenant;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Builds the sales-demo tenants (SLO-183, docs/20 §3.2).
 *
 * Idempotent by default: a demo tenant that already exists is left alone, so
 * the command is safe to run during a demo. `--fresh` is the destructive half —
 * it drops the tenant and rebuilds it, which is what the nightly staging reset
 * uses (SLO-191).
 *
 * Safe to ship enabled on any environment, because the destructive path can
 * only ever reach an `is_demo` tenant: {@see DemoSeeder} refuses a slug owned by
 * a real one, and {@see PurgeDemoTenant} refuses to purge a
 * tenant that is not flagged (docs/20 §1.5).
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed
        {--tenant= : Only this persona, by slug}
        {--fresh : Delete the demo tenant and rebuild it from nothing}';

    protected $description = 'Seed the sales-demo tenants (docs/20).';

    public function handle(DemoSeeder $seeder): int
    {
        $slug = $this->option('tenant');
        $fresh = (bool) $this->option('fresh');

        try {
            $seeded = $seeder->run(is_string($slug) ? $slug : null, $fresh);
        } catch (RuntimeException $e) {
            // A refusal, not a crash: the message names the tenant and why. It
            // is the whole value of the guardrail, so it must not arrive as a
            // stack trace in a cron log.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($seeded === []) {
            $this->info('Nothing to seed: every demo tenant already exists. Use --fresh to rebuild.');

            return self::SUCCESS;
        }

        foreach ($seeded as $builtSlug) {
            $this->line("  seeded {$builtSlug}");
        }

        $this->info(sprintf('Seeded %d demo tenant(s).', count($seeded)));

        return self::SUCCESS;
    }
}
