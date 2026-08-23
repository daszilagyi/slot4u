<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in person's own account security (SLO-149, docs/01 OWASP A07).
 *
 * Registered on both the tenant admin host and the superadmin panel, pointing
 * here — the same page for a tenant-admin who holds a company's whole customer
 * list and for the superadmin who can impersonate into any tenant. Both are
 * accounts worth a second factor; neither had one.
 *
 * ⚠️ Behind `password.confirm`, and that is what lets this page show the
 * recovery codes at all. The threat two-factor exists for is a session somebody
 * else is holding — a page that displayed the codes to whoever had the cookie
 * would hand that person the very thing the second factor is made of.
 */
class SecurityController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // `two_factor_secret` set but unconfirmed means a setup somebody started
        // and did not finish. It is a real state, not an edge case: it is what a
        // closed tab in the middle of scanning a QR leaves behind, and the page
        // has to offer the way out of it rather than pretending it is "off".
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $enabled = $user->two_factor_confirmed_at !== null;

        return Inertia::render('Account/Security', [
            'twoFactor' => [
                'enabled' => $enabled,
                'pending' => $pending,
                // Rendered server-side rather than fetched from Fortify's JSON
                // endpoints: the page is already behind password confirmation, so
                // a second round trip would add a request without adding a check.
                'qrSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
                'secret' => $pending ? decrypt((string) $user->two_factor_secret) : null,
                'recoveryCodes' => $enabled ? $user->recoveryCodes() : [],
            ],
            // Superadmins cannot switch it off (SLO-149). Said on the screen
            // rather than only enforced on submit: a disable button that always
            // fails is worse than no button.
            'required' => $user->isSuperAdmin(),
            'backUrl' => $user->isSuperAdmin() ? '/' : '/dashboard',
        ]);
    }
}
