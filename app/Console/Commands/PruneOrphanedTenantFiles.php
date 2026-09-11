<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CommissionInvoice;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantFiles;
use Illuminate\Console\Command;

/**
 * Removes the files of tenants that no longer exist (SLO-226).
 *
 * Until SLO-226 every demo purge left the purged tenant's invoice PDFs behind,
 * and the nightly `demo:reset` runs in production — so there is a backlog to
 * clear once. After that the purge cleans up after itself and this command
 * should find nothing; it stays as the way to check.
 *
 * Only a hard-deleted tenant can own an orphan: a real tenant is archived
 * (soft delete) or anonymised, never removed, and its invoices must be kept
 * for eight years (docs/19 §7). So "orphaned" means a `tenants/{id}` directory
 * whose id has no row at all, trashed ones included.
 *
 * ⚠️ Reports by default and deletes only with `--force`. Deletion here cannot
 * be undone, and the input is a directory listing, not a database row.
 */
class PruneOrphanedTenantFiles extends Command
{
    protected $signature = 'tenants:prune-orphaned-files
        {--force : Delete the files instead of only listing them}';

    protected $description = 'List (or with --force, delete) files of tenants that no longer exist.';

    public function handle(TenantFiles $files): int
    {
        // A wrong or empty database would make every directory look orphaned.
        // Refusing here is the difference between a no-op and deleting every
        // customer invoice on the host.
        if (! Tenant::withoutGlobalScopes()->withTrashed()->exists()) {
            $this->error('Refusing to run: the tenants table is empty. Is this the right database?');

            return self::FAILURE;
        }

        $existing = Tenant::withoutGlobalScopes()->withTrashed()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $orphans = array_values(array_diff($files->tenantIdsOnDisk(), $existing));

        // Belt and braces: never touch a directory an invoice row still points
        // into. With cascading keys this cannot happen — which is precisely
        // why it is worth one query to be sure.
        $orphans = array_values(array_filter($orphans, fn (int $id): bool => ! $this->referenced($id)));

        if ($orphans === []) {
            $this->info('No orphaned tenant files.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($orphans as $id) {
            $count = $files->count($id);
            $total += $count;
            $this->line(sprintf('  tenant %d: %d file(s)', $id, $count));

            if ($this->option('force')) {
                $files->delete($id);
            }
        }

        $this->info($this->option('force')
            ? sprintf('Deleted %d file(s) of %d tenant(s) that no longer exist.', $total, count($orphans))
            : sprintf('%d file(s) of %d tenant(s) that no longer exist. Run with --force to delete them.', $total, count($orphans)));

        return self::SUCCESS;
    }

    private function referenced(int $tenantId): bool
    {
        $prefix = "tenants/{$tenantId}/%";

        return Invoice::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('pdf_path', 'like', $prefix)->orWhere('storno_pdf_path', 'like', $prefix))
            ->exists()
            || CommissionInvoice::withoutGlobalScopes()
                ->where(fn ($q) => $q->where('pdf_path', 'like', $prefix)->orWhere('storno_pdf_path', 'like', $prefix))
                ->exists();
    }
}
