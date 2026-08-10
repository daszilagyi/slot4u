<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\Backup\BackupShell;

/**
 * Records what the backup subsystem asked the operating system to do (SLO-154).
 *
 * Used only where the real tool cannot run in CI — `mysqldump` against a SQLite
 * test database. Everything else (tar, gzip, openssl) runs for real in the
 * tests, because an archive that was never unpacked proves nothing.
 */
final class FakeBackupShell extends BackupShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<array<string, string>> */
    public array $environments = [];

    /** @var (callable(string, string): string)|null */
    public $onScript;

    /** @var (callable(list<string>, array<string, string>, string): string)|null */
    public $onCommand;

    public function __construct()
    {
        parent::__construct('bash', 5);
    }

    public function script(string $script, string $failureContext): string
    {
        $this->scripts[] = $script;

        return $this->onScript ? ($this->onScript)($script, $failureContext) : '';
    }

    public function command(array $arguments, array $environment, string $failureContext): string
    {
        $this->commands[] = $arguments;
        $this->environments[] = $environment;

        return $this->onCommand ? ($this->onCommand)($arguments, $environment, $failureContext) : '';
    }

    /**
     * The last script whose text contains $needle.
     */
    public function scriptContaining(string $needle): ?string
    {
        foreach (array_reverse($this->scripts) as $script) {
            if (str_contains($script, $needle)) {
                return $script;
            }
        }

        return null;
    }
}
