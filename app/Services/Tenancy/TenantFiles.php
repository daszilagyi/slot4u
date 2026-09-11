<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Actions\Tenant\UpdateTenantSettings;
use App\Jobs\IssueCommissionInvoiceDocument;
use App\Jobs\IssueInvoice;
use App\Jobs\StornoInvoice;
use App\Services\Seo\OgImageGenerator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where a tenant's files live on disk, and how to remove them (SLO-226).
 *
 * Rows go with the tenant through the cascading foreign keys; files do not —
 * nothing in the database knows about them. Every writer below puts its files
 * under a prefix that carries the tenant id, and this class is the one place
 * that lists those prefixes:
 *
 *  - invoicing disk, `tenants/{id}/invoices/` — customer invoices and stornos
 *    ({@see IssueInvoice}, {@see StornoInvoice})
 *  - invoicing disk, `tenants/{id}/commission/` — slot4u's own commission
 *    invoices ({@see IssueCommissionInvoiceDocument})
 *  - public disk, `tenants/{id}/` — logo and cover
 *    ({@see UpdateTenantSettings})
 *  - public disk, `og/{id}-{hash}.png` — the share image
 *    ({@see OgImageGenerator})
 *
 * ⚠️ A new writer that stores per-tenant files must use one of these prefixes
 * or be added here. Otherwise every demo reset leaves its files behind, which
 * is exactly how ~700 invoice PDFs a night came to accumulate on a host with
 * an inode quota.
 */
final class TenantFiles
{
    /**
     * Delete every file the tenant owns. Idempotent: a tenant with no files is
     * not an error.
     */
    public function delete(int $tenantId): void
    {
        foreach ($this->disks() as $disk) {
            $disk->deleteDirectory($this->directory($tenantId));
        }

        $public = Storage::disk('public');
        $public->delete(array_values(array_filter(
            $public->files('og'),
            fn (string $path): bool => $this->ownsShareImage($tenantId, $path),
        )));
    }

    /**
     * Tenant ids that have a `tenants/{id}` directory on either disk.
     *
     * @return list<int>
     */
    public function tenantIdsOnDisk(): array
    {
        $ids = [];

        foreach ($this->disks() as $disk) {
            foreach ($disk->directories('tenants') as $directory) {
                $segment = basename($directory);

                // Only a plain id is ours. Anything else under `tenants/` was not
                // written by this application, and a cleanup must not guess.
                if (ctype_digit($segment)) {
                    $ids[(int) $segment] = true;
                }
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * Number of files the tenant owns, for reporting before a cleanup.
     */
    public function count(int $tenantId): int
    {
        $count = 0;

        foreach ($this->disks() as $disk) {
            $count += count($disk->allFiles($this->directory($tenantId)));
        }

        return $count + count(array_filter(
            Storage::disk('public')->files('og'),
            fn (string $path): bool => $this->ownsShareImage($tenantId, $path),
        ));
    }

    private function directory(int $tenantId): string
    {
        return "tenants/{$tenantId}";
    }

    /**
     * `og/7-…` belongs to tenant 7 and not to tenant 70: the id is matched up to
     * the dash, never as a bare prefix.
     */
    private function ownsShareImage(int $tenantId, string $path): bool
    {
        return str_starts_with(basename($path), "{$tenantId}-");
    }

    /**
     * The invoicing disk and the public one — once, if they are configured to
     * be the same disk.
     *
     * @return list<Filesystem>
     */
    private function disks(): array
    {
        $names = array_values(array_unique([(string) config('invoicing.disk'), 'public']));

        return array_map(fn (string $name): Filesystem => Storage::disk($name), $names);
    }
}
