<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToUserHome;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second half of a sign-in with 2FA lands where the first half would have
 * without it (see RedirectsToUserHome, and LoginResponse). Fortify's default
 * sent everyone to `fortify.home` — `/dashboard` — on the current host: a 404 on
 * the superadmin host, whose home is `/`, and on the central domain, where no
 * dashboard exists at all. The superadmin's second factor is mandatory, so every
 * superadmin who opened the login page directly hit it (SLO-248).
 */
class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    use RedirectsToUserHome;

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return $this->redirectToUserHome($request);
    }
}
