<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateMyPasswordRequest;
use App\Http\Requests\Tenant\UpdateMyProfileRequest;
use App\Models\SocialAccount;
use App\Services\SocialAuth\SocialAuthUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members area — the customer's own profile (SLO-96). Lives in the `/my` group
 * behind auth + ensure.user.tenant + ensure.customer. Every action operates on
 * the acting user's own record ($request->user()), so ownership is implicit —
 * there is no id to authorise, and a customer can never reach another account.
 * Email is read-only: it is the global-unique login identity (docs/03).
 */
class MyProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Tenant/My/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'has_password' => $user->hasPassword(),
            ],
            // Google / Facebook sign-ins (SLO-252): what is linked, and what
            // could be.
            'linked_accounts' => $user->socialAccounts()
                ->withoutGlobalScopes()
                ->orderBy('provider')
                ->get(['id', 'provider', 'email', 'created_at'])
                ->map(fn (SocialAccount $account): array => [
                    'id' => $account->id,
                    'provider' => $account->provider->value,
                    'email' => $account->email,
                ])
                ->values(),
            'social_providers' => SocialAuthUrls::offeredOn($request),
        ]);
    }

    public function update(UpdateMyProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back();
    }

    public function updatePassword(UpdateMyPasswordRequest $request): RedirectResponse
    {
        // The `password` attribute is cast to `hashed`, so assignment hashes it.
        $password = (string) $request->validated('password');
        $request->user()->update(['password' => $password]);

        // Every other session ends on its next request because its stored
        // password hash no longer matches (AuthenticateSession, SLO-99). This
        // call adds the one thing that does not follow from the hash alone: it
        // re-issues THIS device's remember-me cookie, which still carries the
        // old hash and would otherwise sign this device out too, the first time
        // its session expires.
        Auth::logoutOtherDevices($password);

        return back();
    }

    /**
     * A first password for an account created through Google or Facebook
     * (SLO-252) — by mail, never on the spot.
     *
     * ⚠️ Setting it right here would need no proof but the session, and a
     * stolen session would turn into a permanent credential (and, with a
     * password in place, the owner's provider could then be unlinked). The
     * reset link proves the mailbox instead — the same proof "forgot my
     * password" asks for.
     */
    public function sendPasswordLink(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->hasPassword(), 404);

        Password::broker()->sendResetLink(['email' => $user->email]);

        return back()->with('status', __('app.tenant.my.profile.password_link_sent'));
    }
}
