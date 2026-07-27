<?php

declare(strict_types=1);

namespace App\Services\Domain;

/**
 * What an edge provider tells us back about a registered custom hostname
 * (SLO-135): its own id for it, and how far the certificate has got.
 */
final readonly class ProvisionedHostname
{
    public function __construct(
        public string $id,
        public ?string $certificateStatus = null,
    ) {}

    /**
     * Whether the certificate is issued and the hostname actually serves.
     * Anything else (`pending_validation`, `pending_issuance`, …) means the
     * tenant's DNS is still propagating or validation has not completed.
     */
    public function isActive(): bool
    {
        return $this->certificateStatus === 'active';
    }
}
