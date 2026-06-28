<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToUserHome;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain-aware post-login redirect (see RedirectsToUserHome).
 */
class LoginResponse implements LoginResponseContract
{
    use RedirectsToUserHome;

    public function toResponse($request): Response
    {
        return $this->redirectToUserHome($request);
    }
}
