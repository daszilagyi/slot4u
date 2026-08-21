<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

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

    /** The cookie payload. Versioned, so a policy change re-asks. */
    public function toCookieValue(): string
    {
        return (string) json_encode([
            'v' => (string) config('consent.version'),
            'c' => $this->categories,
        ]);
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
