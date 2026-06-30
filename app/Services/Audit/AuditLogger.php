<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Unified audit-logging API (SLO-78). Records a semantic action with its
 * old/new values, resolving the actor and request IP from the current context
 * so callers (Action classes, controllers) stay thin. Entry-point agnostic:
 * works the same from the web panel today and the Public API later.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  int|null  $tenantId  Audited tenant; defaults to the auditable's tenant.
     */
    public function record(
        AuditAction $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $tenantId = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $tenantId ?? $this->tenantIdFor($auditable),
            'user_id' => Auth::id(),
            'action' => $action->value,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * The tenant the audited entity belongs to: its own id when it is a Tenant,
     * otherwise its `tenant_id` column if present, else NULL (platform-wide).
     */
    private function tenantIdFor(?Model $auditable): ?int
    {
        if ($auditable === null) {
            return null;
        }

        if ($auditable instanceof Tenant) {
            return $auditable->getKey();
        }

        $tenantId = $auditable->getAttribute('tenant_id');

        return $tenantId === null ? null : (int) $tenantId;
    }
}
