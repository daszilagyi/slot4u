<?php

namespace App\Http\Responses\Concerns;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain-aware redirect after login/registration. A super-admin lands on the
 * admin panel; a tenant user lands on their own subdomain dashboard. Login and
 * registration may happen on a different host than the target (e.g. the central
 * domain), so cross-origin targets use Inertia's location response — the browser
 * performs a full visit and the shared session cookie (`.{central}`) carries the
 * authentication.
 */
trait RedirectsToUserHome
{
    protected function redirectToUserHome(Request $request): Response
    {
        $url = $this->userHomeUrl($request->user(), $request);

        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return new RedirectResponse($url);
    }

    private function userHomeUrl(User $user, Request $request): string
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
