<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Archives the uploaded-files tree — `storage/app` (SLO-154).
 *
 * That tree holds tenant logos and cover images (`UpdateTenantSettings`) and
 * issued invoice PDFs (`IssueInvoice`), none of which the database can
 * regenerate. It is a separate artifact from the dump on purpose: restoring a
 * booking system starts with the rows, and the rows should not be behind a
 * gigabyte of images in the download queue.
 *
 * The working directory lives outside this tree (`storage/framework/backup`) so
 * that today's archive never ends up inside tomorrow's.
 */
class StorageArchive
{
    public function __construct(private readonly BackupShell $shell) {}

    /**
     * Write a tar.gz of $sourceDirectory to $targetPath, returning its size.
     *
     * Returns null when there is nothing to archive: on a fresh install the tree
     * holds only `.gitignore` files, and an empty artifact per day is noise that
     * makes the useful ones harder to find.
     */
    public function write(string $sourceDirectory, string $targetPath): ?int
    {
        if (! is_dir($sourceDirectory) || $this->isEmpty($sourceDirectory)) {
            return null;
        }

        $this->shell->script(
            sprintf(
                '%s -czf %s -C %s .',
                escapeshellarg((string) config('backup.tar_binary')),
                escapeshellarg($targetPath),
                escapeshellarg($sourceDirectory),
            ),
            'the storage archive failed',
        );

        // Read the whole archive back. tar reports a truncated or corrupt stream
        // as a non-zero exit, which is the only cheap proof that what we are
        // about to upload can be unpacked again.
        $this->shell->script(
            sprintf(
                '%s -tzf %s > /dev/null',
                escapeshellarg((string) config('backup.tar_binary')),
                escapeshellarg($targetPath),
            ),
            'the storage archive could not be read back',
        );

        return (int) @filesize($targetPath);
    }

    /**
     * "Empty" means no real payload: the `.gitignore` files Laravel ships are
     * part of the repository, not of anyone's data.
     */
    private function isEmpty(string $directory): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                return false;
            }
        }

        return true;
    }
}
