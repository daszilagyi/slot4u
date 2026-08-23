<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Longest a single booking may span (SLO-176)
    |--------------------------------------------------------------------------
    |
    | One value, read in two places that must never disagree:
    |
    |   1. `Admin\BookingRequest` refuses a booking longer than this.
    |   2. `AvailabilityService::loadBookings()` uses it as the lower bound on
    |      `starts_at` when looking for bookings that overlap a day.
    |
    | ⚠️ The coupling is the whole point, and it is load-bearing. The overlap
    | test — `starts_at < windowEnd AND ends_at > windowStart` — has no lower
    | bound on `starts_at`, so no index can serve it: the database has to
    | consider every booking the tenant ever made. Measured on a tenant with
    | 55,000 bookings that was a FULL TABLE SCAN of 54,475 rows on the busiest
    | public endpoint, ~400 ms of a ~500 ms page (docs/17 §10).
    |
    | Bounding `starts_at` from below turns it into a range scan of ~745 rows.
    | But the bound is only CORRECT if nothing can be longer than it — a booking
    | that started before the window and is still running would be invisible, and
    | an invisible booking is a slot offered twice. Hence the validation: the
    | guarantee is enforced, not assumed.
    |
    | 24 hours matches every other duration cap in the system: `duration_minutes`,
    | `min/max_duration_minutes` and both buffers are all validated `max:1440`
    | minutes. Until now `ends_at` on an admin-entered booking was the one place
    | with no ceiling at all.
    |
    | Raising this is safe and costs only a slightly wider scan; LOWERING it below
    | the span of bookings that already exist is not — check first:
    |
    |   SELECT COUNT(*) FROM bookings
    |   WHERE TIMESTAMPDIFF(HOUR, starts_at, ends_at) > <new value>;
    |
    */

    'max_span_hours' => (int) env('BOOKING_MAX_SPAN_HOURS', 24),

];
