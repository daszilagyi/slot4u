<?php

namespace App\Enums;

/**
 * Lifecycle of an announced event (docs/04 §3). An event is `scheduled` until an
 * admin cancels it (`canceled` → registrants are notified in M5). Past events
 * are surfaced by their date, not a separate status, in the MVP.
 */
enum EventStatus: string
{
    case Scheduled = 'scheduled';
    case Canceled = 'canceled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
