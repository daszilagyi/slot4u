<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Services\SocialAuth\SocialAuthBroker;
use App\Services\SocialAuth\SocialAuthUrls;
use App\Services\SocialAuth\SocialIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * The provider's callback — step 2 of a social sign-in (SLO-251, docs/28).
 *
 * Central domain only: it is the one redirect URI registered with Google and
 * Meta. It exchanges the code for the provider's identity and hands that to the
 * starting host in a single-use token. It signs nobody in, creates nothing and
 * links nothing: it cannot tell whose browser it is talking to (a provider URL
 * copied out of someone else's redirect lands here just the same), so every
 * decision waits for the starting host, where the nonce proves the browser.
 */
class SocialCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        SocialAuthBroker $broker,
    ): RedirectResponse {
        // By name, like SocialRedirectController (which also runs on tenant hosts).
        $provider = SocialProvider::tryFrom((string) $request->route('provider'));

        abort_if($provider === null || ! $provider->isConfigured(), 404);

        $state = (string) $request->query('state');
        $flow = $broker->takeFlow($state);

        // An unknown, expired or replayed state has no host to go back to —
        // the flow was the only record of it — so this one lands on the central
        // login page.
        if ($flow === null || $flow->provider !== $provider) {
            return redirect('/login')->withErrors(['social' => __('app.auth.social.errors.expired')]);
        }

        [$identity, $error] = $this->identity($request, $provider);

        $token = $broker->handOff($state, $flow, $identity, $error);

        return redirect()->away($flow->consumeUrl.'?'.http_build_query(['token' => $token]));
    }

    /**
     * Exchange the code for the provider's identity — and nothing else.
     *
     * @return array{0: SocialIdentity|null, 1: string|null}
     */
    private function identity(Request $request, SocialProvider $provider): array
    {
        // The person pressed "cancel" on the consent screen.
        if ($request->filled('error') || ! $request->filled('code')) {
            return [null, 'cancelled'];
        }

        try {
            /** @var AbstractProvider $driver */
            $driver = Socialite::driver($provider->value);

            return [SocialIdentity::fromSocialite(
                $provider,
                $driver->stateless()->redirectUrl(SocialAuthUrls::callback($provider))->user(),
            ), null];
        } catch (Throwable $e) {
            // Code exchange failed: a reused or expired code, a network error,
            // a wrong secret. Nothing about the person is known, so nothing is
            // decided — the message says "try again", the log says why.
            Log::warning('Social login: provider exchange failed', [
                'provider' => $provider->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [null, 'provider_error'];
        }
    }
}
