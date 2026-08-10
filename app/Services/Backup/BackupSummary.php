<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * What one backup run did (SLO-154).
 *
 * `skipped` is a first-class outcome, not a failure: a dev machine and CI have
 * no offsite destination and must stay silent. Production tells the difference
 * through the staleness check in `monitor:health`, which is what turns "nothing
 * configured" from a shrug into an incident.
 */
final class BackupSummary
{
    /**
     * @param  array<string, int>  $artifacts  uploaded file name => bytes
     * @param  list<string>  $pruned  run ids deleted by retention
     */
    private function __construct(
        public readonly bool $performed,
        public readonly ?string $runId,
        public readonly string $destination,
        public readonly bool $encrypted,
        public readonly array $artifacts,
        public readonly array $pruned,
        public readonly ?string $skipReason,
    ) {}

    /**
     * @param  array<string, int>  $artifacts
     * @param  list<string>  $pruned
     */
    public static function performed(string $runId, string $destination, bool $encrypted, array $artifacts, array $pruned): self
    {
        return new self(true, $runId, $destination, $encrypted, $artifacts, $pruned, null);
    }

    public static function skipped(string $reason): self
    {
        return new self(false, null, '', false, [], [], $reason);
    }

    public function totalBytes(): int
    {
        return array_sum($this->artifacts);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'run' => $this->runId,
            'destination' => $this->destination,
            'encrypted' => $this->encrypted,
            'bytes' => $this->totalBytes(),
            'artifacts' => array_keys($this->artifacts),
            'pruned' => $this->pruned,
        ];
    }
}
