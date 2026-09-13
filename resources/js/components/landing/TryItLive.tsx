import {
    Brain,
    Dumbbell,
    ExternalLink,
    LayoutDashboard,
    PartyPopper,
    Scissors,
    Store,
    Wrench,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { trackDemo } from '@/lib/analytics';
import { useTranslations } from '@/lib/i18n';
import { useInView, useMediaQuery, useReducedMotion } from '@/lib/motion';
import type { DemoPersona } from '@/types';

/**
 * "Try it live" (SLO-192, docs/21 §2.1) — the landing page's strongest section.
 *
 * The audience does not buy a feature list; they buy an answer to "what will MY
 * customer see". A live frame answers that in ten seconds, and it doubles as
 * proof that the product is running rather than rendered — which no screenshot
 * gallery can do.
 *
 * ⚠️ Three things keep this safe, and none of them are in this file:
 *
 *  - a demo tenant is the only kind whose pages may be framed at all
 *    (SecurityHeaders: `X-Frame-Options` omitted and `frame-ancestors` set for
 *    `is_demo` and nothing else — every other tenant keeps DENY);
 *  - the admin link is a signed, 15-minute, rate-limited URL that 404s against a
 *    tenant holding real data (DemoLoginController);
 *  - the whole demo is rebuilt at 03:00, so nothing a visitor breaks outlasts
 *    the night (`demo:reset`, SLO-191).
 *
 * Drawn in the "Slot4u Landing" look (SLO-229): yellow-bordered selected card,
 * yellow icon tiles, a navy browser frame. The design's frame showed a static
 * mock with invented prices; this one keeps the running demo inside it (Daniel,
 * 2026-09-13), so nothing in it has to be kept in sync by hand.
 */

/** A persona's icon tile. Unknown slugs get a shopfront rather than nothing. */
const PERSONA_ICONS: Record<string, typeof Store> = {
    'demo-pszichologus': Brain,
    'demo-szepsegszalon': Scissors,
    'demo-fitnesz': Dumbbell,
    'demo-rendezvenyhaz': PartyPopper,
    'demo-autoszerviz': Wrench,
};

/**
 * Tailwind's `lg` breakpoint, in JS.
 *
 * ⚠️ Duplicated from CSS on purpose, and it has to stay in step with the
 * `lg:` classes below. A `hidden` iframe still loads its `src` in every current
 * browser, so hiding the frame in CSS alone would have every phone download a
 * whole application it is never shown — the exact cost this section is built to
 * avoid.
 */
const DESKTOP_QUERY = '(min-width: 1024px)';

type Props = {
    personas: DemoPersona[];
    /**
     * Narrow the section to one tenant and drop the card list.
     *
     * ⚠️ This is the parameter docs/22 §5 requires: the vertical landings
     * (SLO-198, `/autoszerviz`) reuse the frame and the view switch around a
     * single persona, with no list to choose from. Built in now, so that issue
     * has nothing to refactor and nothing to fork.
     */
    only?: string;
    /**
     * The section's own heading, lead and caption, when the page around it
     * speaks a trade's language rather than the home page's (SLO-198).
     */
    title?: string;
    lead?: string;
    caption?: string;
    /**
     * Which landing this is, tagged onto every event the section reports
     * (docs/22 §4). Without it the demo funnel of `/autoszerviz` and the funnel
     * of the home page arrive in GA4 as one number, and the reason the vertical
     * pages exist at all is to be measured apart.
     */
    vertical?: string;
};

export default function TryItLive({
    personas,
    only,
    title,
    lead,
    caption,
    vertical,
}: Props) {
    const t = useTranslations();
    const reduced = useReducedMotion();

    // ⚠️ The gate that keeps this section off the LCP path. The iframe is a whole
    // application — by far the heaviest thing on the page — and loading it
    // eagerly would spend the hero's budget on something below the fold
    // (docs/21 §2.1, "Teljesítmény-korlát").
    const [ref, seen] = useInView<HTMLDivElement>({ threshold: 0.1 });
    const desktop = useMediaQuery(DESKTOP_QUERY);

    // Both gates, not either: in view AND on a screen the frame is shown on.
    const framed = seen && desktop;

    const shown = only
        ? personas.filter((item) => item.slug === only)
        : personas;

    const [selected, setSelected] = useState(0);
    const [adminView, setAdminView] = useState(false);

    // Nothing seeded, nothing to show. A section advertising a demo that does not
    // exist is worse than no section at all: its first click is a dead end.
    if (shown.length === 0) {
        return null;
    }

    // The list is dropped when the section was NARROWED to one tenant, not when
    // one happens to be seeded: an installation with a single demo still owes
    // the visitor its name and its "try this" line. The vertical landings pass
    // `only` and supply that context themselves (docs/22 §5).
    const showList = only === undefined;

    const persona = shown[Math.min(selected, shown.length - 1)];
    const host = hostOf(persona.public_url);
    const frameUrl = adminView ? persona.admin_url : persona.public_url;

    /** A per-persona line from the lang file, or null where none is written. */
    const copy = (slug: string, field: string): string | null => {
        const key = `welcome.demo.persona.${slug}.${field}`;
        const value = t(key);

        // `t` echoes the key back when a translation is missing, and a card
        // showing a dotted key is worse than a card showing one line less. This
        // is what lets a fifth persona be seeded before anybody writes its copy.
        return value === key ? null : value;
    };

    /** Every event this section reports, tagged with the landing it happened on. */
    const track: typeof trackDemo = (event, params = {}) =>
        trackDemo(
            event,
            vertical === undefined ? params : { ...params, vertical },
        );

    const select = (index: number, slug: string) => {
        setSelected(index);
        track('demo_select_persona', { persona: slug });
    };

    /**
     * ⚠️ The admin links are signed at render time and die 15 minutes later.
     *
     * A visitor who leaves this tab open past that and then asks for the admin
     * view would meet a 403 inside the frame — which reads as a broken product,
     * not an expired link. So a stale link is replaced rather than followed: a
     * reload re-renders the page and mints a fresh signature, and the visitor
     * lands on a working dashboard one click later than they expected.
     *
     * A minute of margin, because the click has to survive the round trip.
     */
    const adminLinkStale = (): boolean =>
        Date.parse(persona.admin_url_expires_at) - Date.now() < 60_000;

    const refreshForAdmin = (event: {
        preventDefault: () => void;
    }): boolean => {
        if (!adminLinkStale()) {
            return false;
        }

        event.preventDefault();
        window.location.reload();

        return true;
    };

    return (
        <section id="demo" className="scroll-mt-20 bg-canvas">
            <div className="mx-auto w-full max-w-[1440px] px-4 pt-16 pb-[72px] sm:px-8 lg:px-14">
                <h2 className="mb-2.5 text-3xl font-black text-balance text-navy sm:text-4xl">
                    {title ?? t('welcome.demo_title')}
                </h2>
                <p className="max-w-[620px] text-base leading-relaxed text-ink-muted">
                    {lead ?? t('welcome.demo_lead')}
                </p>

                <div
                    ref={ref}
                    className="mt-8 grid gap-8 lg:grid-cols-[0.8fr_1.2fr] lg:items-start"
                >
                    {showList && (
                        <>
                            {/* Mobile: a scrollable chip row. The full cards carry
                                three lines each and four of them would push the
                                frame off a 390px screen entirely. */}
                            <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 lg:hidden">
                                {shown.map((item, index) => (
                                    <button
                                        key={item.slug}
                                        type="button"
                                        onClick={() => select(index, item.slug)}
                                        aria-pressed={index === selected}
                                        className={`shrink-0 rounded-full border-2 px-4 py-2 text-sm font-bold whitespace-nowrap transition-colors duration-200 ease-brand focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none ${
                                            index === selected
                                                ? 'border-navy bg-navy text-white'
                                                : 'border-line bg-white text-navy'
                                        }`}
                                    >
                                        {item.name}
                                    </button>
                                ))}
                            </div>

                            <div className="hidden flex-col gap-3.5 lg:flex">
                                {shown.map((item, index) => (
                                    <PersonaCard
                                        key={item.slug}
                                        persona={item}
                                        size={copy(item.slug, 'size')}
                                        tryThis={copy(item.slug, 'try')}
                                        selected={index === selected}
                                        onSelect={() =>
                                            select(index, item.slug)
                                        }
                                        onAdminClick={refreshForAdmin}
                                        bookLabel={t(
                                            'welcome.demo_book_as_customer',
                                        )}
                                        adminLabel={t(
                                            'welcome.demo_view_admin',
                                        )}
                                    />
                                ))}
                            </div>
                        </>
                    )}

                    <div
                        className={`lg:sticky lg:top-24 ${showList ? '' : 'lg:col-span-2'}`}
                    >
                        <ViewToggle
                            adminView={adminView}
                            onChange={(value) => {
                                if (value && adminLinkStale()) {
                                    window.location.reload();

                                    return;
                                }

                                setAdminView(value);
                                track(
                                    value
                                        ? 'demo_open_admin'
                                        : 'demo_open_public',
                                    {
                                        persona: persona.slug,
                                        surface: 'frame',
                                    },
                                );
                            }}
                            customerLabel={t('welcome.demo_view_customer')}
                            adminLabel={t('welcome.demo_view_admin')}
                        />

                        <BrowserFrame
                            host={host}
                            live={framed}
                            typed={!reduced}
                        >
                            {framed ? (
                                <div
                                    // Keyed on the URL, so switching persona or
                                    // view replaces the document instead of
                                    // navigating inside the frame — a frame with
                                    // its own history turns the browser's back
                                    // button into something nobody expects. The
                                    // remount is also what replays the entrance.
                                    key={frameUrl}
                                    className="h-full w-full animate-in duration-[250ms] fade-in slide-in-from-bottom-2"
                                >
                                    <iframe
                                        src={frameUrl}
                                        title={t('welcome.demo_frame_title', {
                                            name: persona.name,
                                        })}
                                        loading="lazy"
                                        // Same-origin is needed for the app to
                                        // run at all (it has a session); the rest
                                        // is the smallest set a booking flow
                                        // needs — no top-navigation, so the frame
                                        // cannot walk the visitor off this page.
                                        sandbox="allow-same-origin allow-scripts allow-forms allow-popups"
                                        className="h-full w-full border-0 bg-card"
                                    />
                                </div>
                            ) : (
                                // A skeleton, not a screenshot: an image here
                                // would be a request saved by not making it, and
                                // the placeholder is visible for the moment it
                                // takes to scroll into the section.
                                //
                                // ⚠️ docs/21 §2.1 asks for the tenant's own
                                // screenshot here — that asset ships with the
                                // brand pack (SLO-202) and does not exist yet.
                                <div className="h-full w-full animate-pulse bg-brand-100" />
                            )}
                        </BrowserFrame>

                        <p className="mt-3.5 text-xs text-ink-muted">
                            {caption ?? t('welcome.demo_caption')}
                        </p>

                        {/* Mobile: a whole application inside a 390px column is
                            unreadable, so the phone gets a link out instead. The
                            admin view has no mobile form at all — a dashboard is
                            not a phone screen (docs/21 §2.1). */}
                        <a
                            href={persona.public_url}
                            target="_blank"
                            rel="noreferrer"
                            onClick={() =>
                                track('demo_open_public', {
                                    persona: persona.slug,
                                    surface: 'mobile',
                                })
                            }
                            className="mt-4 block rounded-[10px] bg-navy px-4 py-3 text-center font-extrabold text-white transition-colors duration-200 hover:bg-[var(--navy-soft)] focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none lg:hidden"
                        >
                            {t('welcome.demo_open')}
                        </a>
                    </div>
                </div>
            </div>
        </section>
    );
}

function PersonaCard({
    persona,
    size,
    tryThis,
    selected,
    onSelect,
    onAdminClick,
    bookLabel,
    adminLabel,
}: {
    persona: DemoPersona;
    size: string | null;
    tryThis: string | null;
    selected: boolean;
    onSelect: () => void;
    /** Returns true when it handled the click because the link had expired. */
    onAdminClick: (event: { preventDefault: () => void }) => boolean;
    bookLabel: string;
    adminLabel: string;
}) {
    const Icon = PERSONA_ICONS[persona.slug] ?? Store;

    // The lang line reads "Próbáld: …" — the design sets the lead-in in bold.
    const [tryLead, ...tryRest] = (tryThis ?? '').split(':');
    const tryBody = tryRest.join(':').trim();

    return (
        <div
            className={`rounded-[18px] border-2 px-6 py-5 shadow-[0_4px_16px_rgba(15,37,71,.05)] transition-colors duration-200 ease-brand ${
                selected
                    ? 'border-highlight bg-white'
                    : 'border-line bg-canvas/50 hover:bg-white'
            }`}
        >
            <button
                type="button"
                onClick={onSelect}
                aria-pressed={selected}
                className="grid w-full gap-2 text-left focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
            >
                <span className="flex items-center gap-3">
                    <span
                        className="grid size-10 shrink-0 place-items-center rounded-xl bg-highlight text-navy"
                        aria-hidden
                    >
                        <Icon className="size-5" strokeWidth={2} />
                    </span>
                    <strong className="text-[17px] text-navy">
                        {persona.name}
                    </strong>
                    {size !== null && (
                        <span className="ml-auto shrink-0 rounded-full bg-brand-200 px-2.5 py-1 text-[11px] font-extrabold whitespace-nowrap text-brand">
                            {size}
                        </span>
                    )}
                </span>
                {persona.description !== null && (
                    <span className="text-sm leading-normal text-ink-muted">
                        {persona.description}
                    </span>
                )}
                {tryThis !== null && (
                    <span className="text-[13px] leading-normal text-ink-muted">
                        {tryBody !== '' ? (
                            <>
                                <strong className="text-navy">
                                    {tryLead}:
                                </strong>{' '}
                                {tryBody}
                            </>
                        ) : (
                            tryThis
                        )}
                    </span>
                )}
            </button>

            {/* Two ways out of the card, both to a new tab: the visitor came here
                to compare, and a demo that replaces the landing page is a demo
                they have to find their way back from. */}
            <div className="mt-3 flex flex-wrap gap-2.5">
                <a
                    href={persona.public_url}
                    target="_blank"
                    rel="noreferrer"
                    onClick={() =>
                        trackDemo('demo_open_public', {
                            persona: persona.slug,
                            surface: 'card',
                        })
                    }
                    className="inline-flex items-center gap-2 rounded-[10px] bg-navy px-4 py-2.5 text-[13px] font-extrabold whitespace-nowrap text-white transition-colors duration-200 hover:bg-[var(--navy-soft)] focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                >
                    <ExternalLink
                        className="size-3.5"
                        strokeWidth={2}
                        aria-hidden
                    />
                    {bookLabel}
                </a>
                <a
                    href={persona.admin_url}
                    target="_blank"
                    rel="noreferrer"
                    onClick={(event) => {
                        if (onAdminClick(event)) {
                            return;
                        }

                        trackDemo('demo_open_admin', {
                            persona: persona.slug,
                            surface: 'card',
                        });
                    }}
                    className="inline-flex items-center gap-2 rounded-[10px] border-2 border-line bg-white px-4 py-2 text-[13px] font-extrabold whitespace-nowrap text-navy transition-colors duration-200 hover:border-navy focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                >
                    <LayoutDashboard
                        className="size-3.5"
                        strokeWidth={2}
                        aria-hidden
                    />
                    {adminLabel}
                </a>
            </div>
        </div>
    );
}

function ViewToggle({
    adminView,
    onChange,
    customerLabel,
    adminLabel,
}: {
    adminView: boolean;
    onChange: (value: boolean) => void;
    customerLabel: string;
    adminLabel: string;
}) {
    return (
        <div className="mb-3.5 hidden w-fit gap-1 rounded-xl border-2 border-line bg-white p-1 lg:flex">
            {[
                { admin: false, label: customerLabel },
                { admin: true, label: adminLabel },
            ].map((option) => (
                <button
                    key={option.label}
                    type="button"
                    onClick={() => onChange(option.admin)}
                    aria-pressed={adminView === option.admin}
                    className={`rounded-[9px] px-4 py-2 text-[13px] font-extrabold transition-colors duration-200 ease-brand focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none ${
                        adminView === option.admin
                            ? 'bg-navy text-white'
                            : 'text-navy hover:bg-canvas'
                    }`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function BrowserFrame({
    host,
    live,
    typed,
    children,
}: {
    host: string;
    live: boolean;
    typed: boolean;
    children: React.ReactNode;
}) {
    const shownHost = useTypedText(host, typed);

    return (
        <div className="hidden overflow-hidden rounded-[18px] bg-navy shadow-[0_30px_60px_rgba(15,37,71,.2)] lg:block">
            <div className="flex items-center gap-2 border-b border-white/10 px-4 py-3">
                <span
                    className="size-2.5 rounded-full bg-ink-muted"
                    aria-hidden
                />
                <span
                    className="size-2.5 rounded-full bg-ink-muted"
                    aria-hidden
                />
                <span
                    className="size-2.5 rounded-full bg-ink-muted"
                    aria-hidden
                />
                {/*
                    The address bar carries the real host, and that is the whole
                    point: `demo-fitnesz.slot4u.hu` is what the visitor's own
                    subdomain will look like.

                    ⚠️ `aria-live` is deliberately absent. The host retypes itself
                    character by character on every switch, and announcing each
                    frame of that would read the URL aloud a dozen times.
                */}
                <p className="ml-3 truncate font-mono text-xs text-mist">
                    {shownHost}
                </p>
                {/* "Live", said with a green dot — the section's entire claim in
                    one pixel. It only pulses once the frame is actually loading;
                    a dot that pulses over an empty placeholder is a lie. */}
                <span
                    className={`ml-auto size-2 shrink-0 rounded-full bg-ok ${live ? 'animate-pulse' : 'opacity-40'}`}
                    aria-hidden
                />
            </div>
            <div className="aspect-[16/10]">{children}</div>
        </div>
    );
}

/**
 * The address bar typing itself out on every persona switch (docs/21 §2.1).
 *
 * ⚠️ The finished string is what renders when nothing is animating — SSR, a
 * visitor who asked for less motion, and the very first paint. The failure that
 * matters here is an address bar left empty because a timer never ran, so "no
 * animation" has to mean "the whole text, immediately", never "nothing".
 *
 * The progress is stored WITH the text it belongs to, and the visible string is
 * derived during render. That is what lets a switch start from an empty bar
 * without an effect reaching for `setState` on the way in — the new text simply
 * has no progress recorded against it yet.
 */
function useTypedText(text: string, animate: boolean): string {
    const [progress, setProgress] = useState({ text, count: text.length });

    // The page loads with the host already written; only a switch retypes it.
    // Typing it out on arrival would animate something the visitor has not yet
    // looked at, and cost the section a paint it does not need.
    const firstRun = useRef(true);

    useEffect(() => {
        if (!animate || firstRun.current) {
            firstRun.current = false;

            return;
        }

        let index = 0;
        // ~120ms for a hostname of any length, rather than a fixed per-character
        // step: a 28-character subdomain must not take three times as long to
        // finish as a 9-character one.
        const step = Math.max(8, Math.round(120 / Math.max(text.length, 1)));

        const timer = setInterval(() => {
            index += 1;
            setProgress({ text, count: index });

            if (index >= text.length) {
                clearInterval(timer);
            }
        }, step);

        return () => clearInterval(timer);
    }, [text, animate]);

    if (!animate) {
        return text;
    }

    // Progress recorded against a different string means this one has not been
    // typed yet — the empty bar the next tick starts filling.
    return progress.text === text ? text.slice(0, progress.count) : '';
}

/**
 * The host part of the demo URL.
 *
 * Defensive on purpose: `new URL()` throws on anything malformed, and a landing
 * page that white-screens because one seeded tenant has an odd slug is a worse
 * outcome than an address bar showing the raw string.
 */
function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}
