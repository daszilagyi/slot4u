<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far a verified custom domain has got with the edge provider that has to
 * serve it (SLO-135). A null column means "never attempted" — the domain is
 * not verified yet, or the environment has no provider configured at all.
 */
enum DomainProvisioningStatus: string
{
    /** Registered with the provider; the certificate is not issued yet. */
    case Pending = 'pending';

    /** Registered and the certificate is live — the domain actually works. */
    case Active = 'active';

    /** The provider refused or was unreachable. Retryable; ownership stands. */
    case Failed = 'failed';
}
