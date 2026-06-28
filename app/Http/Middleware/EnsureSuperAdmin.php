<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the admin panel (admin.{central}) to platform super-admins
 * (`tenant_id = null`). Must run after the `auth` middleware. Tenant users hit
 * 403 — the admin surface never exists for them.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->isSuperAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
