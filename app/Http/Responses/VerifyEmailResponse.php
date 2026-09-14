<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToUserHome;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After the verification link is followed, the user goes home by role (see
 * RedirectsToUserHome). Fortify's default sends them to `fortify.home` on the
 * current host — but the link lives on APP_URL's central domain, where there is
 * no `/dashboard`, so every new tenant landed on a 404 (SLO-242). An intended
 * URL still wins, and `?verified=1` is kept as Fortify has it.
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    use RedirectsToUserHome;

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->intended($this->userHomeUrl($request->user(), $request).'?verified=1');
    }
}
