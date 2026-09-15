<?php

declare(strict_types=1);

namespace App\Services\SocialAuth;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The two short-lived handles that carry a social sign-in across hosts
 * (SLO-251, docs/28).
 *
 *     starting host ──flow key (OAuth `state`)──▶ provider ──▶ central callback
 *     central callback ──handoff token──▶ starting host /auth/social/consume
 *
 * Both live in the cache, both are single-use, and neither means anything
 * without the other half, which stays in the STARTING host's session: a nonce
 * written when the button was clicked. The consume side refuses a token whose
 * nonce that browser does not hold — otherwise a handoff URL minted for the
 * attacker's own Google account, sent to a victim, would sign the victim into
 * the attacker's account (login CSRF).
 *
 * ⚠️ Single use is `Cache::add` on a "spent" marker, not `Cache::pull`: the
 * production cache store is the database, whose get-then-delete is two
 * statements, whereas `add` is one atomic insert there (and SET NX on Redis).
 */
final class SocialAuthBroker
{
    /** How long a person may spend on the provider's consent screen. */
    public const FLOW_TTL_SECONDS = 600;

    /** How long the redirect from the callback to the starting host may take. */
    public const HANDOFF_TTL_SECONDS = 120;

    private const SESSION_KEY = 'social_login.nonces';

    /** Parallel attempts (tabs) one session may have open. */
    private const MAX_OPEN_FLOWS = 5;

    /**
     * Record a flow and remember its nonce in the starting host's session.
     * Returns the flow key, which travels to the provider as OAuth `state`.
     *
     * @param  callable(string $nonceHash): SocialLoginFlow  $build
     */
    public function start(Session $session, callable $build): string
    {
        $key = Str::random(40);
        $nonce = Str::random(40);

        $flow = $build(hash('sha256', $nonce));

        Cache::put($this->flowCacheKey($key), $flow->toArray(), self::FLOW_TTL_SECONDS);

        /** @var array<string, string> $nonces */
        $nonces = (array) $session->get(self::SESSION_KEY, []);
        $nonces[$key] = $nonce;
        $session->put(self::SESSION_KEY, array_slice($nonces, -self::MAX_OPEN_FLOWS, null, true));

        return $key;
    }

    /** The flow behind an OAuth `state`, exactly once. */
    public function takeFlow(string $key): ?SocialLoginFlow
    {
        $data = $this->spend('flow', $key);

        return $data === null ? null : SocialLoginFlow::fromArray($data);
    }

    /**
     * Mint the token the starting host will redeem: the identity the provider
     * returned, or the lang key of why there is none.
     *
     * ⚠️ An identity, not a decision. Nothing is created or linked on the
     * callback, because the callback cannot tell whose browser it is talking
     * to — a provider authorize URL copied out of somebody else's redirect
     * reaches it just as well. Every write happens on redemption, after the
     * nonce has proven this is the browser that started the flow.
     */
    public function handOff(string $flowKey, SocialLoginFlow $flow, ?SocialIdentity $identity, ?string $error = null): string
    {
        $token = Str::random(64);

        Cache::put($this->handoffCacheKey($token), [
            'flow_key' => $flowKey,
            'flow' => $flow->toArray(),
            'identity' => $identity?->toArray(),
            'error' => $identity === null ? ($error ?? 'expired') : null,
        ], self::HANDOFF_TTL_SECONDS);

        return $token;
    }

    /**
     * Redeem a handoff token on the starting host: once, before it expires, and
     * only in the browser that started the flow.
     *
     * @return array{flow: SocialLoginFlow, identity: SocialIdentity|null, error: string|null}|null
     */
    public function redeem(Session $session, string $token): ?array
    {
        $data = $this->spend('handoff', $token);

        if ($data === null) {
            return null;
        }

        $flowKey = (string) $data['flow_key'];

        /** @var array<string, string> $nonces */
        $nonces = (array) $session->get(self::SESSION_KEY, []);
        $nonce = $nonces[$flowKey] ?? null;
        unset($nonces[$flowKey]);
        $session->put(self::SESSION_KEY, $nonces);

        $flow = SocialLoginFlow::fromArray((array) $data['flow']);

        if (! is_string($nonce) || ! hash_equals($flow->nonceHash, hash('sha256', $nonce))) {
            return null;
        }

        return [
            'flow' => $flow,
            'identity' => is_array($data['identity'] ?? null) ? SocialIdentity::fromArray($data['identity']) : null,
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * Read a cache entry and burn it in the same breath. Tokens are random
     * `[A-Za-z0-9]` strings; anything else is refused before the cache is asked.
     *
     * @return array<string, mixed>|null
     */
    private function spend(string $kind, string $handle): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{40,64}$/', $handle) !== 1) {
            return null;
        }

        $cacheKey = $kind === 'flow' ? $this->flowCacheKey($handle) : $this->handoffCacheKey($handle);

        if (! Cache::add($cacheKey.':spent', true, self::FLOW_TTL_SECONDS)) {
            return null;
        }

        $data = Cache::get($cacheKey);
        Cache::forget($cacheKey);

        return is_array($data) ? $data : null;
    }

    private function flowCacheKey(string $key): string
    {
        return 'social_login:flow:'.hash('sha256', $key);
    }

    private function handoffCacheKey(string $token): string
    {
        return 'social_login:handoff:'.hash('sha256', $token);
    }
}
