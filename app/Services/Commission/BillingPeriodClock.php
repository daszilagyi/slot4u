<?php

declare(strict_types=1);

namespace App\Services\Commission;

use Illuminate\Support\Carbon;

/**
 * Maps between wall-clock time in a tenant's timezone and the `YYYY-MM` billing
 * period key (docs/10 §5.4). A period is a calendar month *in the tenant's own
 * timezone*, so a booking realized at 2026-07-31 23:30 UTC belongs to the August
 * period for a Europe/Budapest tenant.
 *
 * Shared by the recompute (which pins a period's commission settings) and the
 * billing dashboard (which must display the very same threshold/rate the
 * recompute applied — resolving them independently would drift).
 */
final class BillingPeriodClock
{
    /** The period a tenant is currently accruing into. */
    public function currentPeriod(string $timezone): string
    {
        return Carbon::now($timezone)->format('Y-m');
    }

    /**
     * The instant a period's effective commission settings are pinned to: the end
     * of the period's month in the tenant's timezone, but never in the future — a
     * still-open period pins to "now", so a settings version scheduled later this
     * month is not applied early. This keeps a past period auditable: it
     * reconstructs with the settings in force at its close (docs/10 §12/16).
     */
    public function referenceInstant(string $period, string $timezone): Carbon
    {
        // Anchor to the first of the month before ->endOfMonth() so a short month
        // never overflows from createFromFormat filling the day from "today".
        $endOfPeriod = Carbon::createFromFormat('Y-m-d', $period.'-01', $timezone)
            ->endOfMonth()
            ->utc();

        $now = Carbon::now();

        return $endOfPeriod->greaterThan($now) ? $now : $endOfPeriod;
    }
}
