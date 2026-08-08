<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Tests\TestCase;

/**
 * Stands in for Laravel's haveibeenpwned lookup (SLO-145). The real verifier makes
 * an HTTP call, which a test suite must never depend on: the default binding in
 * {@see TestCase} answers "not breached", and the test that proves the rule
 * actually rejects a leaked password swaps in one that answers the opposite.
 */
final class FakeUncompromisedVerifier implements UncompromisedVerifier
{
    public function __construct(private readonly bool $compromised = false) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function verify($data): bool
    {
        return ! $this->compromised;
    }
}
