<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Walls the tenant admin panel off from the customer role (SLO-86).
 *
 * Runs after EnsureUserBelongsToTenant in the tenant chain. The admin panel is
 * staff-only: a user must hold a role other than `customer` to enter. A pure
 * `customer` (or a role-less user) belongs to the members area (SLO-33), a
 * separate route group, and is denied here with 403 — this matters because
 * several admin routes (`/dashboard`, `/showcase`, `/profile`) carry no `can:`
 * gate, so a customer with a valid tenant session would otherwise reach them.
 * A user who is both customer and staff still gets in as staff.
 *
 * The check delegates to {@see User::isStaff()} rather than repeating a role
 * list: since SLO-142 a tenant may define its own roles, and the two must agree
 * — the shared prop that decides where the UI sends a user is the same call, so
 * a divergence here would render an admin nav that 403s on every click.
 *
 * An impersonating super-admin has no tenant role but must act as an admin, so
 * they bypass the check (EnsureUserBelongsToTenant already let them through).
 */
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->isStaff()) {
            abort(403);
        }

        return $next($request);
    }
}
