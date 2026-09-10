<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Impersonation\Impersonation;
use App\Settings\TenantSignupSource;

/**
 * Remembers, for the length of a visit, which campaign brought someone here
 * (SLO-210, docs/19 §12).
 *
 * ⚠️ **The session, not a cookie of its own** — and that is the load-bearing
 * decision in this feature, not an implementation detail.
 *
 * The obvious build is a 30-day first-party attribution cookie. It needs
 * consent: an attribution cookie is not strictly necessary for a service the
 * visitor asked for, so under ePrivacy it sits behind the banner. That is not
 * merely inconvenient — it is **biased in the exact dimension being measured**.
 * Consent rates differ by audience, and the whole purpose here is comparing one
 * audience's vertical landing against another's (docs/22 §7.1). A measurement
 * whose coverage varies with the thing it is comparing is worse than a smaller
 * honest one.
 *
 * The session cookie is already exempt, so putting the campaign inside it adds
 * NOTHING to the visitor's device. What is left is ordinary server-side
 * processing under legitimate interest, and it lands on a company record at
 * sign-up rather than following a person around.
 *
 * ⚠️ The price, stated plainly because a number nobody knows the shape of is
 * the dangerous kind: attribution lives as long as the session (two hours), so
 * a visitor who clicks an ad today and signs up next week is recorded as having
 * no source. We undercount delayed conversions — uniformly, which is why the
 * COMPARISON between verticals survives it even though the absolute totals do
 * not. Read these numbers as "of the people who signed up in the same visit".
 *
 * Shaped after {@see Impersonation}: one small
 * service owning one namespaced session key, so nothing else has to know the
 * key exists.
 */
final class CampaignAttribution
{
    private const KEY = 'campaign_source';

    /**
     * Record this arrival, if it tells us more than what we already have.
     *
     * Two rules, and the second one is the one worth having:
     *
     * 1. First touch wins — someone who arrives, wanders and comes back is
     *    still the same visit, brought by the same thing.
     * 2. ⚠️ **Except that a paid click always beats a source-less arrival.**
     *    Someone who reads the home page organically and only then clicks the
     *    ad would otherwise be filed as organic — we would pay for the click
     *    and then record the traffic as free, which is the one error that makes
     *    the whole comparison argue for the wrong answer.
     */
    public function remember(TenantSignupSource $arrival): void
    {
        if ($arrival->isEmpty()) {
            return;
        }

        $stored = $this->current();

        if (! $stored->isEmpty() && ! ($arrival->hasCampaign() && ! $stored->hasCampaign())) {
            return;
        }

        session()->put(self::KEY, $arrival->toArray());
    }

    public function current(): TenantSignupSource
    {
        $stored = session()->get(self::KEY);

        return TenantSignupSource::fromArray(is_array($stored) ? $stored : null);
    }

    /**
     * Hand over what was remembered and stop remembering it.
     *
     * Taken rather than read: once it is on the tenant row the session copy is
     * a second, staler answer to the same question — and the visitor who
     * registers a second company from the same browser should not silently
     * inherit the first one's campaign.
     */
    public function take(): TenantSignupSource
    {
        $source = $this->current();

        session()->forget(self::KEY);

        return $source;
    }
}
