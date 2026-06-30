<?php

namespace App\Actions\Tenant;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;

/**
 * Superadmin tenant status transition (SLO-77). Archiving soft-deletes the
 * tenant (so it 404s in IdentifyTenant); moving back to an operational status
 * restores a previously archived tenant. Every transition is audit-logged (SLO-78).
 */
class ChangeTenantStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

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
    }
}
