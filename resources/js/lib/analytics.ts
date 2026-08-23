/**
 * Page-view reporting for the measurement tag, when there is one (SLO-172).
 *
 * The tag itself is emitted by the server (`partials/analytics.blade.php`) and
 * only when consent and host allow it. This module never decides anything: it
 * looks for `window.gtag`, and does nothing at all if it is absent. That is what
 * keeps the consent decision in one place — a second, client-side condition
 * could drift out of step with the server's and would be the version nobody
 * tests.
 *
 * Why report views by hand: the root Blade configures gtag with
 * `send_page_view: false`. slot4u is an Inertia SPA, so a hard navigation only
 * happens once; gtag's automatic view would report the entry page and then stay
 * silent for the rest of the session.
 */
import { router } from '@inertiajs/react';

declare global {
    interface Window {
        gtag?: (...args: unknown[]) => void;
    }
}

export function startAnalytics(): void {
    if (typeof window === 'undefined' || typeof window.gtag !== 'function') {
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
            window.gtag?.('event', 'page_view', {
                page_location: window.location.href,
                page_title: document.title,
            });
        });
    });
}
