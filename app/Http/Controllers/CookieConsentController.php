<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CookieConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Records what a visitor decided about non-essential storage (SLO-165).
 *
 * A plain form post rather than a fetch: the decision has to survive on a
 * server-rendered page whose JavaScript may not have loaded, and the redirect
 * back is what makes the next render — banner gone, scripts gated — come from
 * the server rather than from a re-render nobody can verify.
 *
 * Open to anyone, because a visitor deciding not to be tracked cannot be asked
 * to identify themselves first.
 */
class CookieConsentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $rules = ['redirect_to' => ['nullable', 'string', 'max:2048']];

        foreach (CookieConsent::names() as $category) {
            $rules[$category] = ['nullable', 'boolean'];
        }

        $data = $request->validate($rules);

        $granted = [];

        foreach (CookieConsent::names() as $category) {
            $granted[$category] = (bool) ($data[$category] ?? false);
        }

        $consent = CookieConsent::granted($granted);

        // Refusing must be as durable as accepting. A short-lived "no" would ask
        // again on the next visit, which is the pattern that trains people to
        // click accept.
        Cookie::queue(Cookie::make(
            (string) config('consent.cookie'),
            $consent->toCookieValue(),
            (int) config('consent.lifetime_days') * 24 * 60,
        ));

        return back();
    }
}
