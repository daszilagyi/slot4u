<?php

namespace App\Http\Controllers\Super;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\IndexAuditLogRequest;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only superadmin audit-log viewer (SLO-78). Lists the immutable trail of
 * superadmin/tenant operations with action and tenant filters. Lives behind
 * auth + ensure.superadmin (routes/admin.php).
 */
class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $action = $request->validated('action');
        $tenantId = $request->validated('tenant_id');

        $logs = AuditLog::query()
            // Eager-load to avoid N+1 on the actor/tenant columns rendered per row.
            ->with(['actor:id,name,email', 'tenant:id,name,slug'])
            ->when($action, fn ($query) => $query->where('action', $action))
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor === null ? null : [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                ],
                'tenant' => $log->tenant === null ? null : [
                    'id' => $log->tenant->id,
                    'name' => $log->tenant->name,
                    'slug' => $log->tenant->slug,
                ],
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Super/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => ['action' => $action, 'tenant_id' => $tenantId],
            // Known action codes, for the filter dropdown (no per-request query).
            'actions' => array_map(fn (AuditAction $a) => $a->value, AuditAction::cases()),
        ]);
    }
}
