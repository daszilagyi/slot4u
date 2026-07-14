<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Flip tenants from trial to active once their 14-day trial ends (SLO-76).
Schedule::command('tenants:expire-trials')->daily();

// Roll lapsed event waitlist offers to the next waiter (SLO-25). withoutOverlapping
// so a slow run can't double-dispatch a WaitlistOffered notification for one entry.
Schedule::command('waitlist:expire-offers')->hourly()->withoutOverlapping();

// Release approval-pending bookings whose soft hold has lapsed (SLO-26).
Schedule::command('bookings:expire-soft-holds')->hourly()->withoutOverlapping();

// Remind customers of the bookings starting within 24 hours (SLO-110). Hourly, not
// daily: a booking made two days out must still be reminded ~24h before it starts,
// whatever hour that falls on. Re-running is free — the notifications_log claim
// (booking:{id}:reminder_24h) makes the reminder exactly-once, so a missed run is
// caught by the next one. withoutOverlapping keeps a slow run from racing itself.
Schedule::command('bookings:remind')->hourly()->withoutOverlapping();
