<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * A visitor's decision about non-essential storage (SLO-165, docs/19 §11).
 *
 * Read from a cookie, which is what lets the server know before it sends the
 * first byte: a banner whose visibility is decided in the browser flashes on
 * every server-rendered page, and a script gated in the browser has already been
 * downloaded by the time it is gated. Both of those are the failure modes this
 * class exists to avoid.
 *
 * `necessary` is never a field here. It is not a choice, and modelling it as one
 * would suggest a visitor could refuse the session cookie that makes the booking
 * form work.
 *
 * ⚠️ The decision belongs to ONE host (SLO-220, docs/19 §11.6). The marketing
 * site and each tenant are different data controllers (§2), so a yes given to
 * slot4u is not a yes given to a tenant — and the cookie is written host-only to
 * make that structural rather than a rule someone has to remember. Everything
 * about the cookie's shape lives in this class, so the next change to it is one
 * file rather than a hunt.
 */
final class CookieConsent
{
    /**
     * @param  array<string, bool>  $categories
     */
    private function __construct(
        public readonly bool $decided,
        private readonly array $categories,
    ) {}

    /** Nobody has answered yet — the banner shows. */
    public static function undecided(): self
    {
        return new self(false, []);
    }

    /**
     * @param  array<string, bool>  $categories
     */
    public static function granted(array $categories): self
    {
        $allowed = [];

        foreach (self::names() as $name) {
            $allowed[$name] = (bool) ($categories[$name] ?? false);
        }

        return new self(true, $allowed);
    }

    /**
     * The decision this request carries, if it is still about the current
     * categories. A stored decision naming an older version is treated as no
     * decision: a choice made about a different set of options is not a choice
     * about this one.
     */
    public static function fromRequest(Request $request): self
    {
        $raw = $request->cookie((string) config('consent.cookie'));

        if (! is_string($raw) || $raw === '') {
            return self::undecided();
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return self::undecided();
        }

        if ((string) ($decoded['v'] ?? '') !== (string) config('consent.version')) {
            return self::undecided();
        }

        $categories = $decoded['c'] ?? null;

        return is_array($categories) ? self::granted($categories) : self::undecided();
    }

    /**
     * Whether a category may load. Always false while undecided — silence is
     * never a yes.
     *
     * ⚠️ The `decided` half of this is belt-and-braces and cannot currently
     * fail on its own: {@see undecided()} carries an empty category map, so the
     * lookup already returns false. Removing it does not break a single test,
     * which is exactly why it is worth saying out loud rather than trusting a
     * green suite to defend it. It stays because it makes the rule readable
     * here, instead of resting on an invariant two constructors away.
     */
    public function allows(string $category): bool
    {
        return $this->decided && ($this->categories[$category] ?? false);
    }

    /**
     * The cookie payload. Versioned, so a policy change re-asks.
     *
     * Private since SLO-220: the value and the Set-Cookie that carries it are
     * one decision, and letting a caller take the value alone is how a second
     * place ended up choosing the cookie's domain. {@see toCookie()}.
     */
    private function toCookieValue(): string
    {
        return (string) json_encode([
            'v' => (string) config('consent.version'),
            'c' => $this->categories,
        ]);
    }

    /**
     * The Set-Cookie that stores this decision — deliberately with NO Domain.
     *
     * A cookie without a Domain attribute is host-only: the browser sends it
     * back to exactly the host that set it and nowhere else. That is the whole
     * fix for SLO-220. `Domain=.slot4u.hu` made one answer stand for the
     * marketing site and every tenant at once, which put slot4u's own GA4 and a
     * tenant's advertising pixel behind a single click — two different data
     * controllers, one question, asked by only one of them.
     *
     * ⚠️ It has to be stripped rather than not passed: CookieJar::make falls
     * back to `session.domain` for any falsy domain argument, so "leave it out"
     * and "make it host-only" are not the same call. Path, secure and SameSite
     * still come from the session config, which is why the cookie is built by
     * the factory first and narrowed after.
     *
     * Refusing is as durable as accepting: a short-lived "no" would ask again on
     * the next visit, which is the pattern that trains people to click accept.
     */
    public function toCookie(): SymfonyCookie
    {
        return Cookie::make(
            (string) config('consent.cookie'),
            $this->toCookieValue(),
            (int) config('consent.lifetime_days') * 24 * 60,
        )->withDomain(null);
    }

    /**
     * The Set-Cookie that deletes the old domain-wide decision, or null when
     * this browser is not carrying one (SLO-220).
     *
     * Null matters: without the check every response on every host would carry
     * a pointless deletion header, forever, for a cookie almost nobody has.
     *
     * The domain is named explicitly rather than taken from `session.domain`,
     * because ResolveCustomDomain nulls that config on a tenant's own hostname
     * (SLO-42) — and a deletion aimed at the wrong domain silently deletes
     * nothing. On a custom domain there is no shared cookie to begin with: the
     * browser never accepted a `.{central}` cookie from `booking.acme.hu`, so
     * this returns null there and the explicit domain never gets used.
     */
    public static function retireSharedDecision(Request $request): ?SymfonyCookie
    {
        $shared = (string) config('consent.shared_cookie');

        if ($shared === '' || ! is_string($request->cookie($shared))) {
            return null;
        }

        return Cookie::forget($shared, null, '.'.config('tenancy.central_domain'));
    }

    /**
     * What the front end is told. `decided` is separate from the categories
     * because "declined analytics" and "has not been asked" must render
     * differently — one shows the banner, the other does not.
     *
     * @return array{decided: bool, categories: array<string, bool>}
     */
    public function toArray(): array
    {
        $categories = [];

        foreach (self::names() as $name) {
            $categories[$name] = $this->allows($name);
        }

        return [
            'decided' => $this->decided,
            'categories' => $categories,
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        /** @var list<string> */
        return array_values((array) config('consent.categories', []));
    }
}
