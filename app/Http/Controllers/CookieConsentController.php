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

        // Host-only, and the shape of it is CookieConsent's business rather than
        // this controller's (SLO-220): the answer given here is an answer to the
        // data controller behind THIS host, and must not follow the visitor onto
        // the marketing site or another tenant.
        Cookie::queue($consent->toCookie());

        return back();
    }
}
