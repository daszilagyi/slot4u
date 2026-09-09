/**
 * Funnel reporting for whichever measurement tags the server emitted
 * (SLO-172 platform, SLO-56 tenant).
 *
 * The tags themselves come from `partials/analytics.blade.php`, and only for a
 * visitor whose consent covers that vendor. This module never decides anything:
 * it looks for `window.gtag` / `window.fbq` and does nothing at all if they are
 * absent. That is what keeps the consent decision in one place — a second,
 * client-side condition could drift out of step with the server's, and it would
 * be the version nobody tests.
 *
 * It also means the two vendors can be live independently: a visitor who
 * accepted statistics but not marketing has `gtag` and no `fbq`, and every
 * function below quietly reports to the half that exists.
 *
 * Why views are reported by hand: the root Blade configures gtag with
 * `send_page_view: false` and omits Meta's `fbq('track', 'PageView')`. slot4u is
 * an Inertia SPA, so a hard navigation happens once — the vendors' automatic
 * views would report the entry page and then stay silent for the whole booking
 * flow, which is precisely the part worth measuring.
 */
import { router } from '@inertiajs/react';

declare global {
    interface Window {
        gtag?: (...args: unknown[]) => void;
        fbq?: (...args: unknown[]) => void;
    }
}

/** A service, as both vendors want to hear about it. */
export type TrackedItem = {
    id: number | string;
    name: string;
    /** Minor units, as everything else in slot4u carries money. */
    priceMinor: number;
    currency: string;
};

function gtag(...args: unknown[]): void {
    window.gtag?.(...args);
}

function fbq(...args: unknown[]): void {
    window.fbq?.(...args);
}

/** Money crosses to the vendors in major units — that is the only format they take. */
function major(minor: number): number {
    return Math.round(minor) / 100;
}

export function startAnalytics(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Inertia fires `navigate` for the very first render too (its InitialVisit
    // handler ends in fireInitialEvents), so this one listener covers the entry
    // page, every in-app visit, and back/forward — no separate initial call, and
    // therefore no double count on the landing page.
    router.on('navigate', () => {
        // One frame later: `navigate` fires as the page object is swapped in,
        // before React has committed the new <title>. Reporting immediately
        // would attribute every view to the title of the page just left.
        requestAnimationFrame(() => {
            gtag('event', 'page_view', {
                page_location: window.location.href,
                page_title: document.title,
            });
            fbq('track', 'PageView');
        });
    });
}

/** A visitor opened a specific service's booking page. */
export function trackViewItem(item: TrackedItem): void {
    gtag('event', 'view_item', {
        currency: item.currency,
        value: major(item.priceMinor),
        items: [
            {
                item_id: String(item.id),
                item_name: item.name,
                price: major(item.priceMinor),
            },
        ],
    });

    fbq('track', 'ViewContent', {
        content_ids: [String(item.id)],
        content_name: item.name,
        content_type: 'product',
        currency: item.currency,
        value: major(item.priceMinor),
    });
}

/** A visitor submitted the booking form — the last step they control. */
export function trackBeginCheckout(item: TrackedItem): void {
    gtag('event', 'begin_checkout', {
        currency: item.currency,
        value: major(item.priceMinor),
        items: [
            {
                item_id: String(item.id),
                item_name: item.name,
                price: major(item.priceMinor),
            },
        ],
    });

    fbq('track', 'InitiateCheckout', {
        content_ids: [String(item.id)],
        content_name: item.name,
        currency: item.currency,
        value: major(item.priceMinor),
    });
}

/**
 * A booking became real.
 *
 * `transactionId` is the booking code, and it is the deduplication key on both
 * sides: GA4 discards a repeated `transaction_id`, and Meta matches this
 * `eventID` against the server-side Conversions API event that carries the same
 * code. Using the booking code rather than a fresh uuid is what makes that
 * possible without storing an extra id anywhere — the server already knows it,
 * and a retry naturally reuses it.
 *
 * WHEN this fires is the server's call, not the browser's: the confirmation page
 * is a permanent link, so a visitor re-opening it must not book twice over.
 */
export function trackPurchase(purchase: {
    transactionId: string;
    valueMinor: number;
    currency: string;
    itemName?: string | null;
}): void {
    gtag('event', 'purchase', {
        transaction_id: purchase.transactionId,
        currency: purchase.currency,
        value: major(purchase.valueMinor),
        items: purchase.itemName
            ? [{ item_name: purchase.itemName, price: major(purchase.valueMinor) }]
            : [],
    });

    fbq(
        'track',
        'Purchase',
        {
            currency: purchase.currency,
            value: major(purchase.valueMinor),
            ...(purchase.itemName ? { content_name: purchase.itemName } : {}),
        },
        { eventID: purchase.transactionId },
    );
}

/**
 * A step in the demo funnel (SLO-192, docs/21 §2.1).
 *
 * GA4 only, and deliberately: these are product-usage events, not conversions —
 * Meta's catalogue has nothing to map "somebody clicked the fitness card" onto,
 * and inventing a custom pixel event for it would pollute the ad account's
 * optimisation signal with traffic that never buys anything.
 *
 * ⚠️ No parameter here may identify a visitor. The persona slug is a public
 * fixture name and the surface is one of three constants; the events answer
 * "which demo do people open, and from where", which is all they are for.
 */
/**
 * A visitor started the trial sign-up (SLO-198, docs/22 §4).
 *
 * ⚠️ Reported at the CLICK, not at the created account: the landing page cannot
 * see the other side of /register, and a conversion counted only on success
 * would silently drop everybody the form itself loses. What this measures is
 * "the page did its job", which is the question a landing page is optimised
 * against.
 *
 * Both vendors, because they are optimised against different events: GA4's
 * `generate_lead` and Meta's `Lead` are the names each platform's own campaign
 * tooling looks for, and a custom name in either is a number nobody can bid on.
 */
export function trackLead(params: Record<string, string> = {}): void {
    gtag('event', 'generate_lead', params);
    fbq('track', 'Lead', params);
}

export function trackDemo(
    event:
        | 'demo_select_persona'
        | 'demo_open_public'
        | 'demo_open_admin'
        | 'demo_booking_completed'
        | 'demo_cta_register',
    params: Record<string, string> = {},
): void {
    gtag('event', event, params);
}
