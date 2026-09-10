<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Analytics\PlatformAnalytics;
use App\Support\Analytics\TenantAnalytics;
use Illuminate\Http\Request;

/**
 * Which consent categories this request can actually decide anything about
 * (SLO-218, docs/19 §11.5).
 *
 * {@see CookieConsent} answers "what did the visitor say"; this answers the
 * question that has to come first — "is there anything to ask". They are
 * separate because the answer to the second one is not the visitor's: it
 * depends on what the page in front of them is capable of loading.
 *
 * A page whose scope is empty sets nothing but the session cookie, which needs
 * no consent under ePrivacy (docs/19 §11). Asking there is not caution, it is
 * noise: a question whose two answers produce identical pages, put to someone
 * who has to dismiss it before they can see anything. The banner earns its
 * interruption only where an answer changes what loads.
 *
 * ⚠️ This is deliberately NOT an `is_demo` exemption. The embedded demo (SLO-192)
 * is what made the problem visible — a cookie bar inside the iframe that was
 * supposed to show the working product, on the same screen as the marketing
 * site's own bar — but a rule reading "demo tenants do not ask" would be the
 * slippery slope SLO-209 turned down. Demo tenants stop asking here for the
 * same reason any tenant would: they measure nothing. Configure a GA4 property
 * on one and its banner comes back, without a line changing.
 *
 * The gates themselves stay where the loading decision is made. Each analytics
 * class reports the categories it consults, and the class that would emit the
 * tag is the only one that says so — the alternative is a second list of
 * conditions here, free to drift out of step with the first, which is the
 * SLO-150 failure mode (a page that looks right and silently does the opposite).
 */
final class ConsentScope
{
    /**
     * The categories in play here, in the order {@see CookieConsent::names()}
     * declares them, so the banner and the settings dialog agree on order.
     *
     * @return list<string>
     */
    public static function forRequest(Request $request): array
    {
        $gated = [
            ...PlatformAnalytics::gatedCategories($request),
            ...TenantAnalytics::gatedCategories(),
        ];

        // Filtered through the configured list rather than returned as gathered:
        // a gate naming a category the app no longer asks about must not put a
        // dead toggle on the screen. Same rule as CookieConsent — the list is
        // the app's, not the caller's.
        return array_values(array_filter(
            CookieConsent::names(),
            static fn (string $name): bool => in_array($name, $gated, true),
        ));
    }
}
