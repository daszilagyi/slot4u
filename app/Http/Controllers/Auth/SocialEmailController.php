<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialEmailRequest;
use App\Notifications\Platform\SocialEmailConfirmationNotification;
use App\Services\SocialAuth\SocialAuthBroker;
use App\Services\SocialAuth\SocialIdentity;
use App\Services\SocialAuth\SocialLoginCompleter;
use App\Services\SocialAuth\SocialLoginFlow;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The address step for a provider identity without an e-mail (SLO-252,
 * docs/28 §3) — in practice a Facebook account registered with a phone number.
 *
 * The person types an address, we mail a link to it, and only a click on that
 * link — **in the same browser** — lets the sign-in continue with the address
 * as a verified one. Until then nothing is created or linked.
 *
 * ⚠️ Why the same browser. Without it the step is an account takeover: an
 * attacker holding a phone-only Facebook account types the VICTIM's address,
 * the victim receives a genuine-looking "confirm" mail and clicks it, and the
 * attacker's Facebook identity is now linked to the victim's account. Bound to
 * the session that started the attempt, the victim's click confirms nothing —
 * the pending attempt lives in the attacker's session, not theirs.
 */
class SocialEmailController extends Controller
{
    /** Confirmation mails one pending attempt may send. */
    public const MAX_SENDS_PER_ATTEMPT = 3;

    /** Confirmation mails one address may receive per hour. */
    public const MAX_SENDS_PER_ADDRESS = 3;

    public function __construct(private readonly TenantManager $tenants) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return SocialLoginCompleter::fail('/login', 'expired');
        }

        return Inertia::render('Auth/SocialEmail', [
            'provider' => SocialProvider::from((string) $pending['identity']['provider'])->label(),
            'sentTo' => $pending['email'],
        ]);
    }

    public function store(SocialEmailRequest $request, SocialAuthBroker $broker): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return SocialLoginCompleter::fail('/login', 'expired');
        }

        $email = (string) $request->validated('email');

        // ⚠️ This form mails a slot4u-signed letter to any address typed into
        // it, so it is capped twice: per attempt (a phone-only Facebook account
        // is free to make) and per recipient (whatever the attempt or the IP).
        $sends = (int) ($pending['sends'] ?? 0);
        $perAddress = 'social-email:'.hash('sha256', $email);

        if ($sends >= self::MAX_SENDS_PER_ATTEMPT || RateLimiter::tooManyAttempts($perAddress, self::MAX_SENDS_PER_ADDRESS)) {
            throw ValidationException::withMessages([
                'email' => __('app.auth.social.errors.too_many_emails'),
            ]);
        }

        RateLimiter::hit($perAddress, 3600);

        $token = $broker->issueEmailConfirmation((string) $pending['id'], $email);

        $pending['email'] = $email;
        $pending['sends'] = $sends + 1;
        // Only the hash: the session store is not where a live credential
        // should sit, but the confirm step must match the link BEFORE it
        // spends it (below).
        $pending['token_hash'] = hash('sha256', $token);
        $request->session()->put(SocialLoginCompleter::PENDING_KEY, $pending);

        Notification::route('mail', $email)->notify(new SocialEmailConfirmationNotification(
            url: url('/auth/social/email/confirm').'?'.http_build_query(['token' => $token]),
            name: (string) ($pending['identity']['name'] ?? ''),
            siteName: $this->tenants->current()->name ?? (string) config('app.name'),
            minutes: intdiv(SocialAuthBroker::EMAIL_CONFIRMATION_TTL_SECONDS, 60),
        ));

        return redirect('/auth/social/email');
    }

    public function confirm(Request $request, SocialAuthBroker $broker, SocialLoginCompleter $completer): RedirectResponse
    {
        $token = (string) $request->query('token');
        $pending = $this->pending($request);

        // Matched against this browser's pending attempt FIRST, spent only
        // after: a mail scanner (Outlook Safe Links, a corporate gateway)
        // fetching the link without the session must not burn it for the
        // person who then clicks it.
        if ($pending === null || ! is_string($pending['token_hash'] ?? null)
            || ! hash_equals($pending['token_hash'], hash('sha256', $token))) {
            return SocialLoginCompleter::fail('/login', 'email_link_invalid');
        }

        $confirmation = $broker->takeEmailConfirmation($token);

        if ($confirmation === null
            || ! hash_equals((string) $pending['id'], $confirmation['pending_id'])
            || $pending['email'] !== $confirmation['email']) {
            return SocialLoginCompleter::fail('/login', 'email_link_invalid');
        }

        $request->session()->forget(SocialLoginCompleter::PENDING_KEY);

        $flow = SocialLoginFlow::fromArray((array) $pending['flow']);
        $identity = SocialIdentity::fromArray((array) $pending['identity']);

        // The address is now proven by its own mailbox, which is what
        // "verified" means everywhere else in the resolver.
        $confirmed = new SocialIdentity(
            $identity->provider,
            $identity->id,
            $confirmation['email'],
            true,
            $identity->name,
            $identity->avatarUrl,
        );

        return $completer->complete($request, $flow, $confirmed);
    }

    /**
     * The attempt waiting in THIS session for THIS host's tenant, unexpired.
     *
     * @return array{id: string, flow: array<string, mixed>, identity: array<string, mixed>, email: string|null, expires_at: int, sends?: int, token_hash?: string}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(SocialLoginCompleter::PENDING_KEY);

        if (! is_array($pending) || ! isset($pending['id'], $pending['flow'], $pending['identity'], $pending['expires_at'])) {
            return null;
        }

        if ((int) $pending['expires_at'] < Carbon::now()->getTimestamp()
            || ($pending['flow']['tenant_id'] ?? null) !== $this->tenants->id()) {
            $request->session()->forget(SocialLoginCompleter::PENDING_KEY);

            return null;
        }

        /** @var array{id: string, flow: array<string, mixed>, identity: array<string, mixed>, email: string|null, expires_at: int, sends?: int, token_hash?: string} $pending */
        return $pending;
    }
}
