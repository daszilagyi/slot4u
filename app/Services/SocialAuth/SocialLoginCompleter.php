<?php

declare(strict_types=1);

namespace App\Services\SocialAuth;

use App\Actions\SocialAuth\LinkSocialIdentity;
use App\Actions\SocialAuth\ResolveSocialLogin;
use App\Enums\SocialIntent;
use App\Models\User;
use App\Notifications\Platform\SocialAccountChangedNotification;
use App\Support\UserHomeUrl;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

/**
 * The last step of a social sign-in, once the browser is proven (SLO-251,
 * SLO-252, docs/28).
 *
 * Two callers reach it: the handoff-token redemption, and the confirmation link
 * of the "Facebook gave us no address" step. Both have already checked that the
 * browser started the flow and that the flow belongs to this host's tenant —
 * this class does every write, and nothing here may run before those checks.
 */
final class SocialLoginCompleter
{
    /** A provider identity waiting for a mail-confirmed address. */
    public const PENDING_KEY = 'social_login.pending';

    /** How long the address step stays open. */
    public const PENDING_TTL_MINUTES = 30;

    /** Name + address handed to the booking form instead of a sign-in. */
    public const PREFILL_KEY = 'social_login.booking_prefill';

    public function __construct(
        private readonly ResolveSocialLogin $resolve,
        private readonly LinkSocialIdentity $link,
        private readonly TenantManager $tenants,
    ) {}

    public function complete(Request $request, SocialLoginFlow $flow, SocialIdentity $identity): RedirectResponse
    {
        if ($flow->intent === SocialIntent::Link) {
            return $this->link($request, $flow, $identity);
        }

        $tenant = $this->tenants->current();
        $outcome = ($this->resolve)($identity, $tenant);

        if ($outcome->error === 'no_email') {
            return $this->askForAddress($request, $flow, $identity);
        }

        // ⚠️ The address belongs to an account this host may not sign in (in
        // practice another business's customer — e-mail is unique platform-
        // wide). The booking still goes ahead, as a guest, with what the
        // provider told us filled in: exactly what typing the address by hand
        // would have done (ResolvePublicContact), and nothing about the other
        // account is revealed — the person sees their own name and address.
        if ($outcome->error === 'not_available' && $flow->intent === SocialIntent::Booking
            && $identity->email !== null && $identity->emailVerified) {
            $request->session()->flash(self::PREFILL_KEY, [
                'name' => $identity->name ?? '',
                'email' => $identity->email,
            ]);

            return redirect($flow->failurePath());
        }

        if ($outcome->error !== null || $outcome->userId === null) {
            return self::fail($flow->failurePath(), $outcome->error ?? 'expired');
        }

        $user = User::query()->find($outcome->userId);

        // Defence in depth: the resolver already refused these.
        if ($user === null || $user->isSuperAdmin() || $user->anonymized_at !== null
            || ($tenant !== null && $user->tenant_id !== $tenant->id)) {
            return self::fail($flow->failurePath(), 'not_available');
        }

        return $this->signIn($request, $flow, $user);
    }

    public static function fail(string $path, string $error): RedirectResponse
    {
        return redirect($path)->withErrors(['social' => __("app.auth.social.errors.{$error}")]);
    }

    private function signIn(Request $request, SocialLoginFlow $flow, User $user): RedirectResponse
    {
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

        if ($flow->tenantId !== null) {
            // Relative, so a tenant's own domain stays the host.
            return redirect($user->isStaff() ? '/dashboard' : '/my/bookings');
        }

        return redirect()->away(UserHomeUrl::for($user, $request->getScheme()) ?? '/');
    }

    private function link(Request $request, SocialLoginFlow $flow, SocialIdentity $identity): RedirectResponse
    {
        $user = $request->user();

        // Only the account that pressed "link". Anyone else holding this
        // browser now — a different login in between — gets nothing linked.
        if ($user === null || $flow->userId === null || $user->id !== $flow->userId || $user->isStaff()) {
            return self::fail($flow->failurePath(), 'expired');
        }

        $error = ($this->link)($user, $identity);

        if ($error !== null) {
            return self::fail($flow->failurePath(), $error);
        }

        // The owner hears about every new way into their account, wherever
        // the session that added it came from.
        $user->notify(new SocialAccountChangedNotification(
            (string) ($this->tenants->current()->name ?? config('app.name')),
            $identity->provider->label(),
            linked: true,
        ));

        return redirect($flow->failurePath())
            ->with('status', __('app.auth.social.linked', ['provider' => $identity->provider->label()]));
    }

    private function askForAddress(Request $request, SocialLoginFlow $flow, SocialIdentity $identity): RedirectResponse
    {
        $request->session()->put(self::PENDING_KEY, [
            'id' => Str::random(40),
            'flow' => $flow->toArray(),
            'identity' => $identity->toArray(),
            'email' => null,
            'expires_at' => Carbon::now()->addMinutes(self::PENDING_TTL_MINUTES)->getTimestamp(),
        ]);

        return redirect('/auth/social/email');
    }
}
