<?php

declare(strict_types=1);

namespace App\Services\Privacy;

/**
 * The outcome of one retention step (SLO-160).
 *
 * A skipped step is reported rather than omitted: "no `integration_logs` table
 * yet" and "the step ran and matched nothing" look identical in a row count,
 * and only one of them means a retention duty is going unenforced.
 */
final readonly class RetentionStep
{
    private function __construct(
        public string $name,
        public int $affected,
        public ?string $skipped = null,
    ) {}

    public static function done(string $name, int $affected): self
    {
        return new self($name, $affected);
    }

    public static function skipped(string $name, string $reason): self
    {
        return new self($name, 0, $reason);
    }

    public function wasSkipped(): bool
    {
        return $this->skipped !== null;
    }

    public function describe(): string
    {
        return $this->skipped !== null
            ? sprintf('%s: skipped (%s)', $this->name, $this->skipped)
            : sprintf('%s: %d', $this->name, $this->affected);
    }
}
