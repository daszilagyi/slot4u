<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Tenant;

/**
 * Builds a minimal VCALENDAR/VEVENT for a booking (SLO-31 "naptárba mentés").
 * Shared by the public confirmation download and the members area (SLO-33) so
 * the two never drift. Times are emitted as UTC instants (Z-suffixed); the
 * booking's `service` relation must be loaded by the caller.
 */
class IcsBuilder
{
    public static function build(Booking $booking, Tenant $tenant): string
    {
        $start = $booking->starts_at?->copy()->utc()->format('Ymd\THis\Z') ?? '';
        $end = $booking->ends_at?->copy()->utc()->format('Ymd\THis\Z') ?? '';
        $summary = self::escape($booking->service->name.' — '.$tenant->name);
        $location = self::escape($tenant->name);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//slot4u//booking//HU',
            'BEGIN:VEVENT',
            'UID:'.$booking->code.'@'.$tenant->slug,
            'DTSTART:'.$start,
            'DTEND:'.$end,
            'SUMMARY:'.$summary,
            'LOCATION:'.$location,
            'DESCRIPTION:'.self::escape(__('app.tenant.booked.ics_description', ['code' => $booking->code])),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }
}
