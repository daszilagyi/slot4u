<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialIntent;
use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Services\SocialAuth\SocialAuthBroker;
use App\Services\SocialAuth\SocialAuthUrls;
use App\Services\SocialAuth\SocialLoginFlow;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * "Continue with Google / Facebook" — step 1 of a social sign-in (SLO-251,
 * docs/28).
 *
 * Runs on the host the person is on (the central domain, a tenant subdomain or
 * a tenant's own domain) because that is the only place that knows which tenant
 * this is and which browser is asking. It records the flow, leaves a nonce in
 * this host's session, and sends the person to the provider with the flow key
 * as OAuth `state` and the central callback as the redirect URI.
 */
class SocialRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        SocialAuthBroker $broker,
        TenantManager $tenants,
    ): RedirectResponse {
        // From the route by name, not as a method argument: on a tenant host the
        // `{tenant}` segment comes first and would be bound in its place.
        $provider = SocialProvider::tryFrom((string) $request->route('provider'));

        abort_if($provider === null || ! $provider->isConfigured(), 404);

        $intent = SocialIntent::tryFrom((string) $request->query('intent')) ?? SocialIntent::Login;
        $returnPath = SocialAuthUrls::safeReturnPath($request->query('return'));
        $tenantId = $tenants->id();

        // url() rather than the request host: on a tenant's own domain the host
        // has been rewritten for routing, and the URL root pinned back to the
        // host the visitor actually used (ResolveCustomDomain).
        $consumeUrl = url('/auth/social/consume');

        $state = $broker->start($request->session(), fn (string $nonceHash): SocialLoginFlow => new SocialLoginFlow(
            $provider,
            $intent,
            $tenantId,
            $consumeUrl,
            $returnPath,
            $nonceHash,
        ));

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider->value);

        // Stateless: Socialite's own state lives in the session of the host that
        // redirects, which is not the host the callback lands on. The flow key
        // is the state instead, and the nonce is the browser binding.
        return $driver->stateless()
            ->redirectUrl(SocialAuthUrls::callback($provider))
            ->with(['state' => $state])
            ->redirect();
    }
}
