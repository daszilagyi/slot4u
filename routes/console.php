<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Watchdog for the parts nothing supervises (SLO-153): the cron-driven queue
// worker and the scheduler itself. Every five minutes, because the alert has to
// beat a human noticing that confirmation emails stopped. `--beat` marks that
// *the scheduler* ran — a manual run deliberately omits it, so an operator
// investigating a silent host sees the real staleness instead of refreshing it.
// Not withoutOverlapping: a check that skipped itself would be a check that
// stopped reporting.
Schedule::command('monitor:health --beat')->everyFiveMinutes();

// Flip tenants from trial to active once their 14-day trial ends (SLO-76).
Schedule::command('tenants:expire-trials')->daily();

// Roll lapsed event waitlist offers to the next waiter (SLO-25). withoutOverlapping
// so a slow run can't double-dispatch a WaitlistOffered notification for one entry.
Schedule::command('waitlist:expire-offers')->hourly()->withoutOverlapping();

// Release approval-pending bookings whose soft hold has lapsed (SLO-26).
Schedule::command('bookings:expire-soft-holds')->hourly()->withoutOverlapping();

// Release bookings whose online payment window has lapsed (SLO-130). Every ten
// minutes, not hourly: the payment hold is minute-grained (30 min by default), so an
// hourly sweep would hold a slot for twice its advertised window. withoutOverlapping
// keeps a slow run from racing itself; the sweep is idempotent either way.
Schedule::command('bookings:expire-pending-payments')->everyTenMinutes()->withoutOverlapping();

// Remind customers of the bookings starting within 24 hours (SLO-110). Hourly, not
// daily: a booking made two days out must still be reminded ~24h before it starts,
// whatever hour that falls on. Re-running is free — the notifications_log claim
// (booking:{id}:reminder_24h) makes the reminder exactly-once, so a missed run is
// caught by the next one. withoutOverlapping keeps a slow run from racing itself.
Schedule::command('bookings:remind')->hourly()->withoutOverlapping();

// Close each tenant's elapsed billing period and issue the commission invoice
// (SLO-69). Hourly, not daily: tenants span timezones, so each month's grace instant
// (2nd of next month, tenant tz) falls at a different UTC hour — an hourly run closes
// each within the hour. Idempotent (BillingPeriodStatus gate), withoutOverlapping.
Schedule::command('billing:close-periods')->hourly()->withoutOverlapping();

// Dunning: flip overdue commission invoices, remind tenants, and suspend after the
// grace window (SLO-69). Daily is enough — due dates and the grace window are
// day-grained. withoutOverlapping keeps a slow run from racing itself.
Schedule::command('billing:dunning-sweep')->daily()->withoutOverlapping();

// Offsite backup (SLO-154, docs/18). Daily at 02:10 UTC — after midnight in the
// tenant timezone (Europe/Budapest), so a day's bookings are settled, and off the
// hour because every other cron on a shared host fires at :00. No-op wherever no
// destination is configured. withoutOverlapping: a dump that outran its window
// must not have a second mysqldump started on top of it.
Schedule::command('backup:run')->dailyAt('02:10')->withoutOverlapping();

// Custom domain certificates (SLO-135). Cloudflare issues them asynchronously
// while the tenant's DNS propagates and never calls back, so the state has to be
// polled — every ten minutes, because a tenant who just added a domain is
// watching the screen and an hourly sweep would make a working domain look
// broken for most of an hour. No-op wherever no provider is configured.
Schedule::command('domains:refresh-certificates')->everyTenMinutes()->withoutOverlapping();

// Retention (SLO-160, docs/19 §7): purge archived tenants past their 90-day
// grace period and enforce every log retention window. Daily at 03:30 UTC —
// after the backup (02:10) has finished, so the last copy of a tenant's data
// predates the purge by a full window rather than by minutes. Every window is
// day-grained, so a daily pass is exact enough; withoutOverlapping keeps a slow
// run from racing itself.
Schedule::command('privacy:retention-sweep')->dailyAt('03:30')->withoutOverlapping();

// Expired password-reset tokens (Laravel's own command). They are personal data
// with a lifetime measured in minutes, and nothing else prunes them — the table
// otherwise keeps every address that ever asked for a reset.
Schedule::command('auth:clear-resets')->daily();
