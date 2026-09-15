<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SocialAuth\SocialAuthBroker;
use App\Services\SocialAuth\SocialLoginCompleter;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Redeeming the handoff token — step 3 of a social sign-in (SLO-251, docs/28).
 *
 * On the host the flow started on, so the session it creates is the one that
 * host reads: a tenant's own domain has a session of its own, which a sign-in
 * on the central domain could never have reached.
 */
class SocialConsumeController extends Controller
{
    public function __invoke(
        Request $request,
        SocialAuthBroker $broker,
        TenantManager $tenants,
        SocialLoginCompleter $completer,
    ): RedirectResponse {
        $redeemed = $broker->redeem($request->session(), (string) $request->query('token'));

        // ⚠️ Also the answer for a token this browser did not start (login
        // CSRF), and for a flow started on another tenant's host that shares
        // this session cookie (`.{central}`): the flow is bound to its tenant.
        if ($redeemed === null || $redeemed['flow']->tenantId !== $tenants->id()) {
            return SocialLoginCompleter::fail('/login', 'expired');
        }

        ['flow' => $flow, 'identity' => $identity, 'error' => $error] = $redeemed;

        if ($identity === null) {
            return SocialLoginCompleter::fail($flow->failurePath(), $error ?? 'expired');
        }

        // Only now — the browser proven, the tenant matched — may anything be
        // created or linked (SLO-251 security review).
        return $completer->complete($request, $flow, $identity);
    }
}
