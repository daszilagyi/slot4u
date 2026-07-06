<?php

namespace App\Enums;

/**
 * Lifecycle of a waitlist entry (docs/04 §3, SLO-25):
 *
 *   waiting ──seat frees──▶ offered ──books──▶ converted
 *      ▲                        │ window lapses
 *      └────────────────────────┴──▶ expired
 *
 * `waiting` and `offered` are the active states that still hold a place in the
 * queue; `converted` and `expired` are terminal.
 */
enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Offered = 'offered';
    case Converted = 'converted';
    case Expired = 'expired';

    /**
     * Whether the entry still holds a place in the queue (waiting, or holding an
     * active offer). A duplicate join is blocked only against active entries.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Waiting, self::Offered], true);
    }

    /**
     * The status values that count as an active queue place.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isActive()),
        ));
    }
}
