<?php

namespace App\Http\Responses\Concerns;

use App\Models\User;
use App\Support\UserHomeUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain-aware redirect after login/registration. A super-admin lands on the
 * admin panel; a staff tenant user lands on their own subdomain dashboard; a
 * customer lands in the members area (SLO-33) — sending them to `/dashboard`
 * would 403 at `ensure.staff`. Login and registration may happen on a different
 * host than the target (e.g. the central domain), so cross-origin targets use
 * Inertia's location response — the browser performs a full visit and the shared
 * session cookie (`.{central}`) carries the authentication.
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
        // A non-super-admin always carries a tenant (invariant); guard against a
        // broken record producing a bogus `http://.{central}` host.
        return UserHomeUrl::for($user, $request->getScheme()) ?? abort(403);
    }
}
