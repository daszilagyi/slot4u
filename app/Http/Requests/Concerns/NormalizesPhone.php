<?php

namespace App\Http\Requests\Concerns;

use App\Rules\Phone;
use App\Support\PhoneNumber;

/**
 * Shared phone handling for every form that accepts one (SLO-151).
 *
 * The number is rewritten to E.164 *before* validation, the way
 * TenantDomainRequest canonicalizes a hostname: validated() then hands the
 * controller a stored-shape value and no action or controller has to remember to
 * normalize. Anything unparseable is left exactly as typed, so the Phone rule
 * rejects it and the form echoes back what the visitor wrote.
 */
trait NormalizesPhone
{
    protected function normalizePhone(string $key = 'phone'): void
    {
        $value = $this->input($key);

        // A non-string (an array smuggled in as phone[]) is left alone for the
        // `string` rule to reject.
        if (! is_string($value)) {
            return;
        }

        $this->merge([$key => PhoneNumber::normalizeInput($value, $this->phoneRegion())]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function phoneRules(int $max = 50): array
    {
        return Phone::rules($this->phoneRegion(), $max);
    }

    protected function phoneRegion(): string
    {
        return PhoneNumber::regionFor();
    }
}
