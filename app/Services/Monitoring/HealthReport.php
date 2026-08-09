<?php

namespace App\Services\Monitoring;

/**
 * The outcome of one health sweep (SLO-153).
 *
 * A value object rather than a bag of booleans so the command, the alert and the
 * exit code all read the same verdict — and so a test can assert on the finding
 * itself instead of on formatted console output.
 */
final class HealthReport
{
    /**
     * @param  list<HealthCheck>  $checks
     */
    public function __construct(public readonly array $checks) {}

    public function isHealthy(): bool
    {
        return $this->failures() === [];
    }

    /**
     * @return list<HealthCheck>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn (HealthCheck $check) => ! $check->healthy));
    }

    public function summary(): string
    {
        return implode('; ', array_map(
            fn (HealthCheck $check) => $check->name.': '.$check->message,
            $this->failures()
        ));
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        $context = [];

        foreach ($this->checks as $check) {
            $context[$check->name] = ($check->healthy ? 'ok — ' : 'FAILING — ').$check->message;
        }

        return $context;
    }
}
