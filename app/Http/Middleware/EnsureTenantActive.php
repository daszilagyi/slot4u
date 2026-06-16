<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates tenant routes on tenant status. Operational tenants (trial/active)
 * pass through; suspended tenants get a 503 status page; archived tenants 404
 * (they are soft-deleted and never resolve in IdentifyTenant, so this branch
 * is defensive).
 *
 * Must run after IdentifyTenant.
 */
class EnsureTenantActive
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenants->current();

        if ($tenant === null) {
            abort(404);
        }

        if ($tenant->status->isOperational()) {
            return $next($request);
        }

        if ($tenant->status === TenantStatus::Suspended) {
            return Inertia::render('Tenant/Suspended', [
                'tenantName' => $tenant->name,
            ])->toResponse($request)->setStatusCode(503);
        }

        abort(404);
    }
}
