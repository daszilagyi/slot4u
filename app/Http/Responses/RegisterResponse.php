<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToUserHome;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After registration the new tenant admin is logged in and sent to their own
 * subdomain dashboard (see RedirectsToUserHome).
 */
class RegisterResponse implements RegisterResponseContract
{
    use RedirectsToUserHome;

    public function toResponse($request): Response
    {
        return $this->redirectToUserHome($request);
    }
}
