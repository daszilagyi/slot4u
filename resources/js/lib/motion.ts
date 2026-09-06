import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';

/**
 * The motion primitives every animated section is built on (SLO-201, docs/21 §2).
 *
 * Two things live here and nowhere else: whether the visitor wants motion at
 * all, and whether an element has come into view. Both are written once because
 * both are easy to get subtly wrong — a listener that never detaches, an
 * observer that fires on every scroll frame, a media query read once at mount
 * and never again when the visitor changes the setting mid-session.
 *
 * ⚠️ The CSS floor is in `app.css`: `prefers-reduced-motion` already flattens
 * every transition and animation product-wide. `useReducedMotion` is for what
 * CSS cannot reach — a Framer Motion variant, a `requestAnimationFrame` loop, a
 * marquee that has to actually stop rather than run invisibly fast.
 */

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

/**
 * Whether this visitor has asked for less motion.
 *
 * ⚠️ SSR-safe by construction. This project server-renders the public pages
 * (docs/01 §7), where `window` does not exist and no preference is knowable —
 * so the server assumes "no reduction" and the client corrects on hydration.
 * Assuming the opposite would be worse: the server would emit the still frame,
 * and every visitor who wants motion would see the page jump into life.
 *
 * The subscription matters as much as the initial read: a visitor can turn the
 * setting on while the page is open, and a hook that only checks at mount keeps
 * animating at somebody who just asked it to stop.
 */
export function useReducedMotion(): boolean {
    // `useSyncExternalStore` rather than state-plus-effect, and not merely to
    // satisfy a lint rule: a media query IS an external store, and reading it in
    // an effect means the first paint is always the wrong answer, corrected one
    // render later. This reads it during render on the client and takes the
    // server snapshot on the server.
    return useSyncExternalStore(subscribeToReducedMotion, getReducedMotion, getServerReducedMotion);
}

function subscribeToReducedMotion(onChange: () => void): () => void {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return () => {};
    }

    // Subscribed, not just read once: a visitor can turn the setting on while
    // the page is open, and a hook that only checks at mount keeps animating at
    // somebody who has just asked it to stop.
    const query = window.matchMedia(REDUCED_MOTION_QUERY);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

function getReducedMotion(): boolean {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return false;
    }

    return window.matchMedia(REDUCED_MOTION_QUERY).matches;
}

/**
 * ⚠️ The server cannot know the preference, so it assumes motion is wanted.
 *
 * The opposite default would be worse: the server would render the still frame,
 * and every visitor who does want motion would watch the page jump into life on
 * hydration. This way the correction only reaches the minority who asked for
 * less — and for them the CSS floor in app.css has already flattened everything
 * before any JavaScript runs.
 */
function getServerReducedMotion(): boolean {
    return false;
}

type InViewOptions = {
    /** How much of the element must be visible before it counts. */
    threshold?: number;
    /** Grow the viewport so an entrance can start just before the element arrives. */
    rootMargin?: string;
    /**
     * Keep reporting `true` after the first sighting (the default).
     *
     * An entrance animation that replays every time the visitor scrolls past is
     * a page that will not settle down, so sections want this. A lazy loader
     * that can unload again would pass `false`.
     */
    once?: boolean;
};

/**
 * Whether an element has entered the viewport.
 *
 * The scroll-entrance half of docs/21 §2, and the gate the heavier sections use
 * to defer work: the trust marquee, a Lottie loop, the demo iframe. Loading
 * those eagerly is what turns a fast hero into a slow page (SLO-203's LCP
 * budget), and `IntersectionObserver` is the cheap way to wait — no scroll
 * handler, no layout read per frame.
 *
 * Returns a ref to attach and the flag:
 *
 *     const [ref, seen] = useInView<HTMLDivElement>();
 *     <section ref={ref}>{seen ? <Heavy /> : <Placeholder />}</section>
 *
 * ⚠️ Reports `true` immediately where the API is missing — SSR, and old
 * browsers. A section that never appears because its observer never ran is a
 * blank page; showing it un-animated is the safe failure.
 */
export function useInView<T extends HTMLElement>(
    options: InViewOptions = {},
): [React.RefObject<T | null>, boolean] {
    const { threshold = 0.15, rootMargin = '0px 0px -10% 0px', once = true } = options;

    const ref = useRef<T>(null);
    const [inView, setInView] = useState(false);

    // Wrapped so the observer callback — which fires outside React's render
    // pass, where a state update is perfectly ordinary — is not mistaken for a
    // synchronous update inside the effect body.
    const reveal = useCallback(() => setInView(true), []);
    const hide = useCallback(() => setInView(false), []);

    useEffect(() => {
        const element = ref.current;

        if (element === null) {
            return;
        }

        if (typeof IntersectionObserver === 'undefined') {
            // Next frame rather than now: a synchronous setState here would
            // cascade a second render out of the first paint, and the element is
            // being revealed unconditionally anyway — one frame later is
            // invisible to anyone.
            const frame = requestAnimationFrame(() => setInView(true));

            return () => cancelAnimationFrame(frame);
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    reveal();

                    // Stop watching once it has been seen — for a one-shot
                    // entrance the observer has no further work, and leaving it
                    // attached keeps a callback alive for the page's lifetime.
                    if (once) {
                        observer.disconnect();
                    }

                    return;
                }

                if (!once) {
                    hide();
                }
            },
            { threshold, rootMargin },
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, [threshold, rootMargin, once, reveal, hide]);

    return [ref, inView];
}

/**
 * A staggered entrance delay, in milliseconds.
 *
 * docs/21 §2 asks for cards that arrive in sequence rather than together — 80ms
 * apart on a card grid, 40ms on the hero's slot chips. One helper so the rhythm
 * is the same everywhere, and so a section does not hard-code a number that
 * drifts from the rest.
 *
 * Returns 0 when motion is reduced: the whole point is that everything is
 * already there.
 */
export function stagger(index: number, step = 80, reduced = false): number {
    return reduced ? 0 : index * step;
}
