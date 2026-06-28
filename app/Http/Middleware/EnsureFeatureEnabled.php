<?php

namespace App\Http\Middleware;

use App\Enums\Feature as FeatureEnum;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Third link of the tenant middleware chain (docs/01):
 * IdentifyTenant → EnsureTenantActive → EnsureFeatureEnabled → can:.
 *
 * Gates a route on a tenant feature flag. The flag is resolved by Pennant
 * against the current tenant (FeatureServiceProvider). A disabled or unknown
 * feature aborts 403 with a translated message — the capability is simply off
 * for this tenant, not hidden, so 403 (not 404) is the honest signal.
 *
 * Usage: `->middleware('ensure.feature:feature_waitlist')`.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $case = FeatureEnum::tryFrom($feature);

        if ($case === null || ! Feature::active($case->value)) {
            abort(403, __('errors.feature_disabled'));
        }

        return $next($request);
    }
}
