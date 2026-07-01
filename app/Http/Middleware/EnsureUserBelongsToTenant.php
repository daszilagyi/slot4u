<?php

namespace App\Http\Middleware;

use App\Services\Impersonation\Impersonation;
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
 *   — unless they are impersonating *this* tenant (SLO-79), in which case they
 *   are let through and act inside it (audited under their own identity);
 * - a user from another tenant gets 403 (they may not operate inside a tenant
 *   that is not theirs).
 *
 * Must run after the `auth` middleware (a user is guaranteed) and after
 * IdentifyTenant (the current tenant is bound).
 */
class EnsureUserBelongsToTenant
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly Impersonation $impersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenants->current();

        if ($tenant === null) {
            abort(404);
        }

        $user = $request->user();

        if ($user->isSuperAdmin()) {
            // An impersonating superadmin may enter the tenant they started a
            // session for; any other tenant sends them back to the admin panel.
            if ($this->impersonation->tenantId() === $tenant->getKey()) {
                return $next($request);
            }

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
