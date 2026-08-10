<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Encrypts a finished artifact before it leaves the host (SLO-154).
 *
 * The format is deliberately boring: `openssl enc -aes-256-cbc -pbkdf2 -salt`,
 * decryptable with one command on any machine that has openssl. A restore must
 * never depend on this application being installable — the situation in which
 * you need the backup is precisely the one where it is not.
 *
 * The passphrase travels in the child process's environment, not in its argv,
 * because argv is world-readable through `ps` on a shared host.
 *
 * Off (empty passphrase) is a supported configuration: the bucket is private
 * either way. It is the weaker of the two postures and docs/18 §2 says so.
 */
class ArtifactEncryptor
{
    private const ENV_KEY = 'SLOT4U_BACKUP_PASSPHRASE';

    public function __construct(private readonly BackupShell $shell) {}

    public function enabled(): bool
    {
        return (string) config('backup.passphrase') !== '';
    }

    /**
     * Encrypt in place, returning the path of the encrypted file.
     *
     * The plaintext is removed only after openssl reported success, so a failed
     * encryption leaves the run with something to fail on rather than nothing.
     */
    public function encrypt(string $path): string
    {
        if (! $this->enabled()) {
            return $path;
        }

        $target = $path.'.enc';

        $this->shell->command(
            [
                (string) config('backup.openssl_binary'),
                'enc',
                '-aes-256-cbc',
                // Pinned explicitly rather than left to the openssl build's
                // default: the machine that decrypts this in two years is not
                // this machine, and a changed default would look like a wrong
                // passphrase.
                '-md', 'sha256',
                '-pbkdf2',
                '-iter', '100000',
                '-salt',
                '-pass', 'env:'.self::ENV_KEY,
                '-in', $path,
                '-out', $target,
            ],
            [self::ENV_KEY => (string) config('backup.passphrase')],
            'encrypting '.basename($path).' failed',
        );

        @unlink($path);

        return $target;
    }
}
