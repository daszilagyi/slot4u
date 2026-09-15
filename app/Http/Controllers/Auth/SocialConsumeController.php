<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\SocialAuth\ResolveSocialLogin;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SocialAuth\SocialAuthBroker;
use App\Support\UserHomeUrl;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

/**
 * Redeeming the handoff token — step 3 of a social sign-in (SLO-251, docs/28).
 *
 * On the host the flow started on, so the session it creates is the one that
 * host reads: a tenant's own domain has a session of its own, which a sign-in
 * on the central domain could never have reached.
 */
class SocialConsumeController extends Controller
{
    public function __invoke(Request $request, SocialAuthBroker $broker, TenantManager $tenants, ResolveSocialLogin $resolve): RedirectResponse
    {
        $redeemed = $broker->redeem($request->session(), (string) $request->query('token'));

        // ⚠️ Also the answer for a token this browser did not start (login
        // CSRF), and for a flow started on another tenant's host that shares
        // this session cookie (`.{central}`): the flow is bound to its tenant.
        if ($redeemed === null || $redeemed['flow']->tenantId !== $tenants->id()) {
            return $this->fail('expired');
        }

        ['flow' => $flow, 'identity' => $identity, 'error' => $error] = $redeemed;
        $tenant = $tenants->current();

        if ($identity === null) {
            return $this->fail($error ?? 'expired');
        }

        // Only now — the browser proven, the tenant matched — may anything be
        // created or linked (SLO-251 security review).
        $outcome = $resolve($identity, $tenant);

        if ($outcome->error !== null || $outcome->userId === null) {
            return $this->fail($outcome->error ?? 'expired');
        }

        $user = User::query()->find($outcome->userId);

        // Defence in depth: the resolver already refused these.
        if ($user === null || $user->isSuperAdmin() || $user->anonymized_at !== null
            || ($tenant !== null && $user->tenant_id !== $tenant->id)) {
            return $this->fail('not_available');
        }

        // ⚠️ A provider login is a FIRST factor. An account that has a second
        // one still has to present it — the same hand-over Fortify's own
        // password login makes to the challenge screen.
        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect('/two-factor-challenge');
        }

        Auth::login($user);
        $request->session()->regenerate();

        if ($flow->returnPath !== null) {
            return redirect($flow->returnPath);
        }

        if ($tenant !== null) {
            // Relative, so a tenant's own domain stays the host.
            return redirect($user->isStaff() ? '/dashboard' : '/my/bookings');
        }

        return redirect()->away(UserHomeUrl::for($user, $request->getScheme()) ?? '/');
    }

    private function fail(string $error): RedirectResponse
    {
        return redirect('/login')->withErrors(['social' => __("app.auth.social.errors.{$error}")]);
    }
}
