<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;

/**
 * Refuses to take the second factor off a superadmin account (SLO-149).
 *
 * ⚠️ Server-side, not just a hidden button. The security page omits the disable
 * control for a superadmin, but Fortify's endpoint is a plain DELETE that anyone
 * with the session can issue by hand — and "anyone with the session" is exactly
 * the person the requirement exists to stop. A rule enforced only in the markup
 * is a rule enforced only against honest users.
 *
 * Bound over Fortify's own action in FortifyServiceProvider, so the rule lives
 * in the domain layer rather than in a middleware that has to recognise a URL.
 *
 * ⚠️ There is a deliberate way back for a superadmin who lost their
 * authenticator: `php artisan two-factor:reset <email>` on the host, which
 * requires shell access — the same access that could edit the database directly.
 * It is a documented recovery path, not a new way in (docs/03).
 */
class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    public function __invoke($user)
    {
        if ($user instanceof User && $user->isSuperAdmin()) {
            abort(403, __('app.security.two_factor.cannot_disable'));
        }

        parent::__invoke($user);
    }
}
