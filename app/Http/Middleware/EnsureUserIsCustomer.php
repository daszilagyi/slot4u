<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the tenant members area (SLO-33) to the customer role — the mirror of
 * {@see EnsureUserIsStaff} for the admin panel. Runs after EnsureUserBelongsToTenant
 * in the tenant chain, so the user is already a confirmed member of this tenant.
 *
 * The members area (`/my/...`) is a customer's own account (their bookings,
 * profile). Staff belong to the admin panel; a staff-only user hitting `/my`
 * gets 403. An impersonating super-admin has no tenant role but must be able to
 * inspect the tenant, so they bypass the check.
 */
class EnsureUserIsCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->hasRole(Role::Customer->value)) {
            abort(403);
        }

        return $next($request);
    }
}
