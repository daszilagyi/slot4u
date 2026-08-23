<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces every retention window in `config/privacy.php` (SLO-160, docs/19 §7).
 *
 * One pass = one step per window, each independent of the others: a step that
 * finds nothing, or cannot run because its table does not exist yet, does not
 * stop the rest. Every step is an overwrite or a delete of rows already past
 * their window, so the whole sweep is idempotent — running it twice in a day
 * changes nothing the first run did not, and a killed run is simply resumed by
 * the next scheduled one.
 *
 * The sweep deliberately holds no tenant context. The tenant global scope
 * no-ops outside a request ({@see TenantScope}), which is
 * exactly what a platform-wide job needs, and every query here is either
 * cross-tenant by nature (the log tables) or filters `tenant_id` explicitly
 * ({@see PurgeTenant}).
 */
final class RetentionSweep
{
    public function __construct(private readonly PurgeTenant $purge) {}

    /**
     * @return list<RetentionStep>
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return [
            $this->purgeArchivedTenants($now),
            $this->redactNotificationLog($now),
            $this->redactAuditLogIps($now),
            $this->pruneAuditLogs($now),
            $this->pruneSessions($now),
            $this->pruneIntegrationLogs($now),
            $this->pruneConversions($now),
        ];
    }

    /**
     * Archived tenants whose grace period has run out.
     *
     * The candidate list is read up front and each tenant is then re-checked
     * under a row lock inside {@see PurgeTenant::purge()}, so a tenant restored
     * between the two is skipped rather than emptied. `$cutoff` is computed once
     * for the whole run: a sweep that took an hour must not measure its last
     * tenant against a later deadline than its first.
     */
    private function purgeArchivedTenants(Carbon $now): RetentionStep
    {
        $cutoff = $now->copy()->subDays($this->days('archived_tenant_days'));
        $purged = 0;

        Tenant::onlyTrashed()
            ->where('status', TenantStatus::Archived->value)
            ->whereNull('purged_at')
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById($this->chunk(), function ($tenants) use ($cutoff, &$purged): void {
                foreach ($tenants as $tenant) {
                    if ($this->purge->purge($tenant, $cutoff)) {
                        $purged++;
                    }
                }
            });

        return RetentionStep::done('archived_tenants_purged', $purged);
    }

    /**
     * Old send-ledger rows lose the address they were sent to.
     *
     * Redacted, never deleted: the (tenant, type, dedupe_key) uniqueness on this
     * table is what makes a notification exactly-once, and dropping a row could
     * resurrect a send. Only `recipient` is personal data, so only `recipient`
     * goes.
     *
     * ⚠️ A consequence worth knowing: an art. 15 export ({@see
     * PersonalDataExport}) matches ledger rows by recipient, so notifications
     * older than this window no longer appear in a customer's own copy. That is
     * data minimisation working as intended — the address genuinely no longer
     * exists — not a gap in the export.
     */
    private function redactNotificationLog(Carbon $now): RetentionStep
    {
        $cutoff = $now->copy()->subDays($this->days('notification_log_days'));

        $affected = NotificationLog::query()
            ->where('created_at', '<', $cutoff)
            ->where('recipient', '!=', AnonymizeCustomer::REDACTED_RECIPIENT)
            ->update(['recipient' => AnonymizeCustomer::REDACTED_RECIPIENT]);

        return RetentionStep::done('notification_log_redacted', $affected);
    }

    /**
     * The audit trail outlives the IP address that produced it. The row is the
     * security record of what a staff member did; the caller's IP stops being
     * useful for an investigation long before that record does.
     */
    private function redactAuditLogIps(Carbon $now): RetentionStep
    {
        $cutoff = $now->copy()->subDays($this->days('audit_log_ip_days'));

        $affected = AuditLog::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('ip_address')
            ->update(['ip_address' => null]);

        return RetentionStep::done('audit_log_ips_redacted', $affected);
    }

    /**
     * Audit rows past their window are deleted outright — unlike the send
     * ledger, nothing downstream depends on the row still existing.
     */
    private function pruneAuditLogs(Carbon $now): RetentionStep
    {
        $cutoff = $now->copy()->subDays($this->days('audit_log_days'));

        // Deleted in chunks rather than one statement: the trail is the largest
        // table on a busy install, and a single unbounded DELETE is the kind of
        // lock a nightly job should not take. The chunk is selected by id and
        // then deleted by id — `DELETE ... LIMIT` is not portable (SQLite needs
        // a compile-time flag for it), and this job also runs in the test suite.
        $deleted = $this->deleteInChunks(
            fn () => AuditLog::query()->where('created_at', '<', $cutoff),
            'audit_logs',
        );

        return RetentionStep::done('audit_logs_deleted', $deleted);
    }

    /**
     * Idle session rows hold a user id, an IP and a user agent.
     *
     * The cutoff is never closer than `session.lifetime`, whatever the
     * configured window says: a retention setting must not be able to log a
     * signed-in user out.
     */
    private function pruneSessions(Carbon $now): RetentionStep
    {
        $table = (string) config('session.table', 'sessions');

        if (! Schema::hasTable($table)) {
            return RetentionStep::skipped('sessions_deleted', "no {$table} table");
        }

        $days = $this->days('session_days');
        $cutoff = min(
            $now->copy()->subDays($days)->getTimestamp(),
            $now->copy()->subMinutes(max(1, (int) config('session.lifetime', 120)))->getTimestamp(),
        );

        $deleted = DB::table($table)->where('last_activity', '<', $cutoff)->delete();

        return RetentionStep::done('sessions_deleted', $deleted);
    }

    /**
     * External-call logs (docs/06: 90 days).
     *
     * ⚠️ The table does not exist yet — docs/02 specifies `integration_logs`,
     * but the M6 payment work shipped without it. The step is written now so the
     * window is enforced from the table's first day; until then it reports
     * itself skipped, which is a visible "not enforced" rather than a silent
     * zero.
     */
    private function pruneIntegrationLogs(Carbon $now): RetentionStep
    {
        if (! Schema::hasTable('integration_logs')) {
            return RetentionStep::skipped('integration_logs_deleted', 'no integration_logs table');
        }

        $cutoff = $now->copy()->subDays($this->days('integration_log_days'));

        $deleted = $this->deleteInChunks(
            fn () => DB::table('integration_logs')->where('created_at', '<', $cutoff),
            'integration_logs',
        );

        return RetentionStep::done('integration_logs_deleted', $deleted);
    }

    /**
     * Ad-conversion rows past their window (SLO-173).
     *
     * These are the rows the sweep exists for in the most literal sense: a
     * `pending` row for a booking that never became a sale holds the visitor's
     * Meta cookie identifiers and will never be sent, so after a while it is
     * personal data being kept for a purpose that has expired.
     *
     * Guarded on the table existing, like the integration log above, so a host
     * that has not run the migration yet reports the step skipped rather than
     * failing the whole daily run.
     */
    private function pruneConversions(Carbon $now): RetentionStep
    {
        if (! Schema::hasTable('analytics_conversions')) {
            return RetentionStep::skipped('analytics_conversions_deleted', 'no analytics_conversions table');
        }

        $cutoff = $now->copy()->subDays($this->days('conversion_days'));

        $deleted = $this->deleteInChunks(
            fn () => DB::table('analytics_conversions')->where('created_at', '<', $cutoff),
            'analytics_conversions',
        );

        return RetentionStep::done('analytics_conversions_deleted', $deleted);
    }

    /**
     * Delete everything `$query` matches, `chunk` rows at a time.
     *
     * Each pass re-runs the builder, so the id list is always read from the
     * current state of the table — an interrupted sweep resumes cleanly, and a
     * row inserted mid-run is simply matched (or not) on its own merits.
     *
     * @param  callable(): (Builder<covariant \Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder)  $query
     */
    private function deleteInChunks(callable $query, string $table): int
    {
        $deleted = 0;

        while (true) {
            /** @var list<int> $ids */
            $ids = $query()
                ->orderBy('id')
                ->limit($this->chunk())
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($ids === []) {
                return $deleted;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        }
    }

    private function days(string $key): int
    {
        return max(1, (int) config('privacy.retention.'.$key));
    }

    private function chunk(): int
    {
        return max(1, (int) config('privacy.chunk', 500));
    }
}
