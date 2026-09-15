<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a social sign-in was started (SLO-251, docs/28).
 *
 * Deliberately NOT a role. Which role an account has is a fact about the
 * account, and the only thing a flow may create is a customer on a tenant host;
 * letting the button say "admin" would be a request nobody should be able to
 * make from a browser.
 */
enum SocialIntent: string
{
    /** The login / registration page. */
    case Login = 'login';

    /** The details step of the public booking wizard (SLO-252). */
    case Booking = 'booking';
}
