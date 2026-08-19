<?php

namespace App\Actions\Tenant;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Notifications\TenantArchivedNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\TenantAdminNotifier;
use Illuminate\Support\Carbon;

/**
 * Superadmin tenant status transition (SLO-77). Archiving soft-deletes the
 * tenant (so it 404s in IdentifyTenant); moving back to an operational status
 * restores a previously archived tenant. Every transition is audit-logged (SLO-78).
 *
 * Archiving also starts the retention clock (SLO-160): `deleted_at` is what the
 * nightly sweep measures the 90-day grace window from, so this is the moment the
 * tenant has to be told its deadline.
 */
class ChangeTenantStatus
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantAdminNotifier $notifier,
    ) {}

    public function __invoke(Tenant $tenant, TenantStatus $status): void
    {
        $oldStatus = $tenant->status;

        if ($status === TenantStatus::Archived) {
            $tenant->update(['status' => $status]);
            $tenant->delete();
        } else {
            if ($tenant->trashed()) {
                $tenant->restore();
            }

            $tenant->update(['status' => $status]);
        }

        $this->audit->record(
            action: match ($status) {
                TenantStatus::Suspended => AuditAction::TenantSuspended,
                TenantStatus::Active => AuditAction::TenantActivated,
                TenantStatus::Archived => AuditAction::TenantArchived,
                TenantStatus::Trial => AuditAction::TenantStatusChanged,
            },
            auditable: $tenant,
            oldValues: ['status' => $oldStatus->value],
            newValues: ['status' => $status->value],
        );

        $this->notifyIfArchived($tenant, $oldStatus, $status);
    }

    /**
     * Mail the tenant admins the retention deadline — but only on the
     * transition *into* the archived state.
     *
     * Re-archiving an already-archived tenant is a no-op that must not restart
     * the clock in the reader's mind by sending a second, later deadline: the
     * sweep still measures from the original `deleted_at`.
     */
    private function notifyIfArchived(Tenant $tenant, TenantStatus $from, TenantStatus $to): void
    {
        if ($to !== TenantStatus::Archived || $from === TenantStatus::Archived) {
            return;
        }

        $archivedAt = $tenant->deleted_at ?? Carbon::now();
        $purgeAt = $archivedAt->copy()->addDays((int) config('privacy.retention.archived_tenant_days'));

        $this->notifier->notify($tenant, new TenantArchivedNotification($tenant, $purgeAt));
    }
}
