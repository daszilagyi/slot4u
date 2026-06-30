<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request UI locale (SLO-9) outside tenant context: authenticated
 * user preference, then the app default. SetLocale runs in the global `web`
 * stack, BEFORE the IdentifyTenant route middleware; on tenant domains
 * IdentifyTenant overrides the locale with the tenant's afterwards. Since the
 * `locale`/`translations` Inertia props are shared lazily (resolved at render),
 * the effective resolution order is: tenant locale → user locale → app default.
 *
 * The `current()` guard keeps this correct even if the middleware order ever
 * changes (never override an already-bound tenant's locale).
 */
class SetLocale
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenants->current() === null) {
            $locale = $request->user()?->locale;

            if (is_string($locale) && $locale !== '') {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
