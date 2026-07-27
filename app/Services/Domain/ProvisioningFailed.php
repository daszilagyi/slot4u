<?php

declare(strict_types=1);

namespace App\Services\Domain;

use RuntimeException;

/**
 * The edge provider refused a custom hostname, or could not be reached
 * (SLO-135). Never fatal to ownership: the domain stays verified and the
 * attempt is retryable.
 */
class ProvisioningFailed extends RuntimeException
{
    public static function fromApi(string $message): self
    {
        return new self($message);
    }
}
