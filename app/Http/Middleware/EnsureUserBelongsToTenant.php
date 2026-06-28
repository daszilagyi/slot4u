<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fourth link of the tenant middleware chain (docs/01):
 * IdentifyTenant → EnsureTenantActive → [auth] → EnsureUserBelongsToTenant
 * → ensure.feature → can.
 *
 * Keeps tenant context private to its own members:
 * - a super-admin has no tenant home, so they are redirected to the admin panel
 *   (tenant impersonation arrives with SLO-14);
 * - a user from another tenant gets 403 (they may not operate inside a tenant
 *   that is not theirs).
 *
 * Must run after the `auth` middleware (a user is guaranteed) and after
 * IdentifyTenant (the current tenant is bound).
 */
class EnsureUserBelongsToTenant
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenants->current();

        if ($tenant === null) {
            abort(404);
        }

        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $scheme = $request->getScheme();
            $central = config('tenancy.central_domain');

            return new RedirectResponse($scheme.'://'.config('tenancy.admin_subdomain').'.'.$central.'/');
        }

        if ($user->tenant_id !== $tenant->getKey()) {
            abort(403);
        }

        return $next($request);
    }
}
