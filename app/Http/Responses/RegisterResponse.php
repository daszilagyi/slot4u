<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToUserHome;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After registration the new user is logged in and sent home by role
 * (see RedirectsToUserHome): a new tenant admin to their subdomain dashboard
 * (SLO-76), a self-registered customer to the members area (SLO-95).
 */
class RegisterResponse implements RegisterResponseContract
{
    use RedirectsToUserHome;

    public function toResponse($request): Response
    {
        return $this->redirectToUserHome($request);
    }
}
