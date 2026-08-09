<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health thresholds (SLO-153)
    |--------------------------------------------------------------------------
    |
    | What `monitor:health` treats as broken. Tuned for the shared-hosting
    | profile (SLO-125), where the queue and the scheduler are cron lines firing
    | every minute — so a gap measured in a quarter of an hour is many missed
    | runs, not a slow one.
    |
    */

    'queue' => [
        // Minutes without a worker loop before the queue counts as dead. The cron
        // fires every minute; 15 leaves room for a long job or a busy host.
        'stale_after_minutes' => (int) env('MONITORING_QUEUE_STALE_MINUTES', 15),

        // How many failed jobs may sit in the table before it is an incident.
        // One: a failed job is a customer who did not get their confirmation.
        'failed_jobs_threshold' => (int) env('MONITORING_FAILED_JOBS_THRESHOLD', 1),
    ],

    'scheduler' => [
        'stale_after_minutes' => (int) env('MONITORING_SCHEDULER_STALE_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead man's switch
    |--------------------------------------------------------------------------
    |
    | URL of an external heartbeat monitor (healthchecks.io, Better Stack, ...)
    | that `monitor:health` pings — but only when every check passed.
    |
    | This is the half of the design that survives the app dying: Sentry can only
    | report an error while the process still runs, and a scheduler that stopped
    | firing reports nothing at all. The external service alerts on the *absence*
    | of a ping, which is the one signal a dead host can still send.
    |
    | Empty (the default) means no outgoing call is ever made — dev and CI stay
    | silent, like the Sentry DSN.
    |
    */

    'heartbeat_url' => (string) env('MONITORING_HEARTBEAT_URL', ''),

    'heartbeat_timeout_seconds' => (int) env('MONITORING_HEARTBEAT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Browser error reporting
    |--------------------------------------------------------------------------
    |
    | The frontend's own Sentry DSN. It is compiled into the bundle at build time
    | (VITE_*, docs/16 §3.3) — this copy exists because the server has to name the
    | ingest origin in the CSP's connect-src, or the browser blocks every report
    | it tries to send (the SLO-150 lesson: a policy that silently kills an
    | integration).
    |
    | Falls back to the backend DSN: both projects live in the same Sentry
    | organisation, so they share an ingest host.
    |
    */

    'browser_dsn' => (string) env('VITE_SENTRY_DSN', ''),

];
