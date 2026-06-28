<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain-aware post-login redirect. A super-admin lands on the admin panel; a
 * tenant user lands on their own subdomain dashboard. Because login may happen
 * on a different host than the target (e.g. the central domain), cross-origin
 * redirects use Inertia's location response so the browser performs a full visit
 * and the shared session cookie (`.{central}`) carries the authentication.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $url = $this->homeUrlFor($request->user(), $request);

        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return new RedirectResponse($url);
    }

    private function homeUrlFor(User $user, Request $request): string
    {
        $scheme = $request->getScheme();
        $central = config('tenancy.central_domain');

        if ($user->isSuperAdmin()) {
            return $scheme.'://'.config('tenancy.admin_subdomain').'.'.$central.'/';
        }

        // A non-super-admin always carries a tenant (invariant); guard against a
        // broken record producing a bogus `http://.{central}` host.
        $tenant = $user->tenant;

        if ($tenant === null) {
            abort(403);
        }

        return $scheme.'://'.$tenant->slug.'.'.$central.'/dashboard';
    }
}
