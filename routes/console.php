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
