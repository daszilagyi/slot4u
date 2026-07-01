<?php

namespace App\Actions\Impersonation;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Impersonation\Impersonation;

/**
 * Ends the active impersonation session (SLO-79), auditing the exit before the
 * session flag is cleared. Returns the tenant id that was being impersonated
 * (null when there was no active session) so the caller can redirect back to
 * that tenant's admin page.
 */
class StopImpersonation
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(): ?int
    {
        $tenantId = $this->impersonation->tenantId();

        if ($tenantId !== null) {
            $this->audit->record(
                action: AuditAction::ImpersonationStopped,
                // withTrashed: an impersonated tenant may have been archived
                // mid-session; we still want the audit entry to reference it.
                auditable: Tenant::withTrashed()->find($tenantId),
                tenantId: $tenantId,
            );
        }

        $this->impersonation->stop();

        return $tenantId;
    }
}
