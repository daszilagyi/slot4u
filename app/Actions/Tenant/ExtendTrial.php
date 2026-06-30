<?php

namespace App\Actions\Tenant;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;

/**
 * Grants/extends a tenant's trial (SLO-77): puts the tenant back on `trial`
 * with a fresh window from now. Restores the tenant if it was archived.
 * Audit-logged (SLO-78).
 */
class ExtendTrial
{
    public const DEFAULT_DAYS = 14;

    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Tenant $tenant, int $days = self::DEFAULT_DAYS): void
    {
        $old = [
            'status' => $tenant->status->value,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
        ];

        if ($tenant->trashed()) {
            $tenant->restore();
        }

        $tenant->update([
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->addDays($days),
        ]);

        $this->audit->record(
            action: AuditAction::TenantTrialExtended,
            auditable: $tenant,
            oldValues: $old,
            newValues: [
                'status' => $tenant->status->value,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            ],
        );
    }
}
