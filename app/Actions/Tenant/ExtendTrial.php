<?php

namespace App\Actions\Tenant;

use App\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * Grants/extends a tenant's trial (SLO-77): puts the tenant back on `trial`
 * with a fresh window from now. Restores the tenant if it was archived.
 */
class ExtendTrial
{
    public const DEFAULT_DAYS = 14;

    public function __invoke(Tenant $tenant, int $days = self::DEFAULT_DAYS): void
    {
        if ($tenant->trashed()) {
            $tenant->restore();
        }

        $tenant->update([
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->addDays($days),
        ]);
    }
}
