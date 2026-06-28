<?php

namespace App\Actions\Tenant;

use App\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * Superadmin tenant status transition (SLO-77). Archiving soft-deletes the
 * tenant (so it 404s in IdentifyTenant); moving back to an operational status
 * restores a previously archived tenant.
 */
class ChangeTenantStatus
{
    public function __invoke(Tenant $tenant, TenantStatus $status): void
    {
        if ($status === TenantStatus::Archived) {
            $tenant->update(['status' => $status]);
            $tenant->delete();

            return;
        }

        if ($tenant->trashed()) {
            $tenant->restore();
        }

        $tenant->update(['status' => $status]);
    }
}
