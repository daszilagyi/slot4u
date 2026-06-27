<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant from the {tenant} subdomain route parameter and
 * binds it into the request lifecycle.
 *
 * Reserved labels or unknown slugs resolve to 404 (cross-tenant probing must
 * not leak existence). Soft-deleted (archived) tenants are excluded from the
 * lookup, so they 404 here too.
 */
class IdentifyTenant
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('tenant');

        if (! is_string($slug) || $this->isReserved($slug)) {
            abort(404);
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            abort(404);
        }

        $this->tenants->set($tenant);
        app()->setLocale($tenant->locale);

        // Scope spatie role/permission checks to this tenant's team.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        return $next($request);
    }

    private function isReserved(string $slug): bool
    {
        $reserved = array_merge(
            (array) config('tenancy.reserved_subdomains', []),
            [config('tenancy.admin_subdomain')],
        );

        return in_array($slug, $reserved, true);
    }
}
