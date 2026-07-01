<?php

namespace App\Actions\Impersonation;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Impersonation\Impersonation;

/**
 * Begins a superadmin impersonation session for a tenant (SLO-79) and records
 * it in the audit trail. Entry-point agnostic (Action layer) so the audit entry
 * is created the same way regardless of caller.
 */
class StartImpersonation
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Tenant $tenant): void
    {
        $this->impersonation->start($tenant);

        $this->audit->record(
            action: AuditAction::ImpersonationStarted,
            auditable: $tenant,
        );
    }
}
