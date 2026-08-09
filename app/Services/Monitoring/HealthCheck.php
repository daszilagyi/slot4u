<?php

namespace App\Services\Monitoring;

/**
 * One named verdict in a health sweep (SLO-153).
 */
final class HealthCheck
{
    public function __construct(
        public readonly string $name,
        public readonly bool $healthy,
        public readonly string $message,
    ) {}

    public static function ok(string $name, string $message): self
    {
        return new self($name, true, $message);
    }

    public static function failing(string $name, string $message): self
    {
        return new self($name, false, $message);
    }
}
