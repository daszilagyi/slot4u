<?php

namespace App\Http\Controllers\Super;

use App\Actions\Impersonation\StartImpersonation;
use App\Actions\Impersonation\StopImpersonation;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Superadmin tenant impersonation (SLO-79).
 *
 * `store` runs on the admin domain (behind auth + ensure.superadmin) and starts
 * a session, then bounces the superadmin into the tenant subdomain. `stop` runs
 * on the tenant domain (where the exit banner lives, so the request is
 * same-origin) and returns them to that tenant's admin page. Both cross-domain
 * hops use Inertia::location, which triggers a full page visit.
 */
class ImpersonationController extends Controller
{
    public function store(Request $request, Tenant $tenant, StartImpersonation $start): Response
    {
        // Only an operational tenant has a reachable admin surface: a suspended
        // one would bounce to its 503 page (EnsureTenantActive) and archived
        // tenants 404 on binding above. Block the direct request the hidden UI
        // button already prevents, so impersonation never dead-ends.
        abort_unless($tenant->status->isOperational(), 403);

        $start($tenant);

        return Inertia::location($this->tenantUrl($request, $tenant->slug, '/dashboard'));
    }

    public function stop(Request $request, StopImpersonation $stop): Response
    {
        $tenantId = $stop();

        $path = $tenantId === null ? '/' : '/tenants/'.$tenantId;

        return Inertia::location($this->adminUrl($request, $path));
    }

    private function tenantUrl(Request $request, string $slug, string $path): string
    {
        return $request->getScheme().'://'.$slug.'.'.config('tenancy.central_domain').$path;
    }

    private function adminUrl(Request $request, string $path): string
    {
        return $request->getScheme().'://'.config('tenancy.admin_subdomain').'.'.config('tenancy.central_domain').$path;
    }
}
