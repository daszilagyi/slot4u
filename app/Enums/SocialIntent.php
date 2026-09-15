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

    /**
     * A signed-in user adding a provider on their profile (SLO-252). The flow
     * records who started it, and only that same user may complete it.
     */
    case Link = 'link';

    /** Where a refused attempt is reported, when the flow carries no return path. */
    public function fallbackPath(): string
    {
        return match ($this) {
            self::Login => '/login',
            self::Booking => '/book',
            self::Link => '/my/profile',
        };
    }
}
