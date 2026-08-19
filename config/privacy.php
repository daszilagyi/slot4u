<?php

declare(strict_types=1);

/**
 * Retention policy — how long each kind of personal data lives (SLO-160,
 * docs/19 §7).
 *
 * Every window the platform enforces is declared here and nowhere else, so
 * "what do we keep, and for how long" is one file an auditor can read. The
 * `privacy:retention-sweep` command turns each entry into one step; changing a
 * number here changes the behaviour with no code change.
 *
 * Windows are deliberately *not* env-driven. A retention period is a
 * documented legal position, not a per-environment knob — and a `.env` typo
 * that silently shortens one would destroy data no backup restores into the
 * right place.
 */
return [

    'retention' => [

        /*
         * Days an archived tenant's data survives before the purge
         * ({@see App\Services\Privacy\PurgeTenant}).
         *
         * 90 days is the window docs/01 and docs/03 §105 already promised, and
         * the tenant is told the exact date at the moment it is archived — the
         * grace period is only lawful if the controller knows about it and can
         * still export ({@see App\Services\Privacy\TenantDataExport}).
         */
        'archived_tenant_days' => 90,

        /*
         * Days before a send-ledger row loses the address it was sent to.
         *
         * The row itself is never deleted: `notifications_log` carries the
         * (tenant, type, dedupe_key) uniqueness that makes a notification
         * exactly-once, and dropping a row could resurrect a send. Only the
         * `recipient` column is personal data, so only that is redacted — the
         * same "overwrite, don't delete" shape the customer erasure uses.
         */
        'notification_log_days' => 90,

        /*
         * Days before an audit row loses the caller's IP address.
         *
         * The trail records *what staff did*, which is its own legal basis and
         * outlives this window; the originating IP is the one field in it that
         * identifies a person beyond the actor id, and it stops being useful
         * for an investigation long before the row does.
         */
        'audit_log_ip_days' => 90,

        /*
         * Days before an audit row is deleted outright.
         *
         * Two years covers any realistic "who changed this" investigation. It
         * is deliberately shorter than the eight-year accounting window
         * (Szt. 169. §) because the accounting evidence is the *invoice*, not
         * the audit row that mentions it — the invoice and its PDF are kept
         * regardless (docs/19 §3.3).
         */
        'audit_log_days' => 730,

        /*
         * Days before an idle session row is deleted.
         *
         * A session row holds a user id, an IP and a user agent. Laravel's own
         * garbage collector is a per-request lottery, so rows outlive their
         * usefulness on a quiet install. The sweep never cuts closer than
         * `session.lifetime`, so this can never log a live user out.
         */
        'session_days' => 30,

        /*
         * Days before an integration-log row is deleted (docs/06 §"Retention:
         * 90 nap").
         *
         * ⚠️ The `integration_logs` table does not exist yet — docs/02 specifies
         * it, but payments (M6) shipped without it. The window is declared now
         * so the sweep enforces it on the day the table lands; until then the
         * step reports itself as skipped rather than failing the daily run.
         */
        'integration_log_days' => 90,
    ],

    /*
     * Rows per chunk in every sweep step. Small enough that a killed run loses
     * little work, large enough that a day's backlog is a handful of queries.
     */
    'chunk' => 500,
];
