<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the superadmin panel behind a second factor (SLO-149, docs/03).
 *
 * The account this guards is the one that sees every tenant and can impersonate
 * into any of them — a stolen password there is not one company's problem, it is
 * every company's. Tenant-admins are asked rather than forced (docs/03): their
 * blast radius is their own customer list, and locking a paying customer out of
 * their own booking system is a worse trade than the risk it removes.
 *
 * ⚠️ The security page is deliberately registered OUTSIDE this gate. A middleware
 * that redirected to a page it also guards would be a loop, and the person would
 * have no way to satisfy the requirement it is enforcing.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        return redirect('/security')->with('status', __('app.security.two_factor.required_notice'));
    }
}
