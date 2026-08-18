<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a {@see PrivacyRequestType} request stands (SLO-159).
 *
 * `Rejected` is deliberately a real, recorded outcome rather than a deletion of
 * the row: a refused erasure is exactly the case the tenant may later have to
 * justify (art. 12 (4) — the controller must inform the subject of the reasons),
 * so it has to leave a trace with its reason attached.
 */
enum PrivacyRequestStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /** Whether the tenant still owes the subject an answer. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
