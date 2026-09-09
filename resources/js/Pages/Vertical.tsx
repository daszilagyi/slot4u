import { Head } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    CalendarCheck,
    CalendarDays,
    CalendarX2,
    FileText,
    PhoneOff,
    ShieldCheck,
    Timer,
    type LucideIcon,
} from 'lucide-react';

import HeroSlotPreview, { type PreviewSlot } from '@/components/HeroSlotPreview';
import Faq from '@/components/landing/Faq';
import TryItLive from '@/components/landing/TryItLive';
import MarketingLayout from '@/Layouts/MarketingLayout';
import { trackLead } from '@/lib/analytics';
import { formatMoney, formatRate } from '@/lib/format';
import { useTranslations, useTranslationTree } from '@/lib/i18n';
import { stagger, useInView, useReducedMotion } from '@/lib/motion';
import type { DemoPersona } from '@/types';

/**
 * A trade-specific landing page — `/autoszerviz` first, the other four after it
 * (SLO-198, docs/22 §4).
 *
 * ⚠️ There is not one Hungarian word in this file, and that is the feature. The
 * whole page is rendered from `verticals.{slug}` in the lang file, so the next
 * trade is a block of copy and a line in `config/verticals.php` — no component
 * to fork, no second page to keep in step with this one when the design moves.
 * The moment a string appears here, the template stops being a template.
 *
 * What it does NOT abstract is the layout. The sections are in the order
 * docs/22 §4 sets out, in that shape, because the shape is the argument: pain
 * first, then the three steps, then the features in the trade's own words, then
 * a running copy of the product, then the honest limits, and only then the
 * price. Making the order configurable would have bought nothing and lost the
 * one thing the page is for.
 */

/** The content block a vertical is built from — `lang/hu/app.php`, `verticals.*`. */
type VerticalContent = {
    meta_title: string;
    meta_description: string;

    eyebrow: string;
    title_lead: string;
    title_accent: string;
    title_tail: string;
    lead: string;
    cta_primary: string;
    cta_secondary: string;
    cta_caption: string;

    widget: { title: string; day: string };

    pains: { title: string; items: IconItem[] };
    steps: { title: string; lead: string; items: TextItem[] };
    features: { title: string; items: IconItem[] };
    demo: { title: string; lead: string; caption: string };
    not_for: { title: string; lead: string; items: string[]; footnote: string };
    pricing: { title: string; lead: string };
    faq: { title: string; items: { q: string; a: string }[] };
    closing: { title: string; lead: string };
};

type TextItem = { title: string; body: string };
type IconItem = TextItem & { icon: string };

/**
 * The commission terms as the server resolved them — the same object the home
 * page gets, from the same service.
 */
type CommissionTerms = {
    free_threshold_minor: number;
    rate_bps: number;
    rate_with_integration_bps: number;
    monthly_cap_minor: number | null;
    currency: string;
    example_turnover_minor: number;
    example_billable_base_minor: number;
    example_commission_minor: number;
};

type Props = {
    vertical: string;
    commission: CommissionTerms | null;
    /** At most one — the demo tenant this vertical frames. Empty when unseeded. */
    demo_personas: DemoPersona[];
    demo_tenant: string | null;
    og_image: string;
    canonical: string;
};

/**
 * Icon names the copy may ask for.
 *
 * ⚠️ A registry rather than an import per vertical: the lang file names an icon
 * as a string, and an unknown name renders the block with no glyph instead of
 * throwing. A landing page must never be a blank screen because somebody typed
 * `calender-check`.
 */
const ICONS: Record<string, LucideIcon> = {
    'bar-chart': BarChart3,
    bell: Bell,
    'calendar-check': CalendarCheck,
    'calendar-days': CalendarDays,
    'calendar-x': CalendarX2,
    'file-text': FileText,
    'phone-off': PhoneOff,
    'shield-check': ShieldCheck,
    timer: Timer,
};

/**
 * The illustrated Saturday in the hero widget.
 *
 * Times, not copy — hour steps because that is what a wheel change takes, and a
 * grid offering 09:45 for it is a detail the one visitor this page is written
 * for reads as wrong. 11:00 is gone, 12:00 is the one being chosen.
 */
const WORKSHOP_SLOTS: readonly PreviewSlot[] = [
    { time: '08:00', state: 'free' },
    { time: '09:00', state: 'free' },
    { time: '10:00', state: 'taken' },
    { time: '11:00', state: 'taken' },
    { time: '12:00', state: 'chosen' },
    { time: '13:00', state: 'free' },
];

export default function Vertical({
    vertical,
    commission,
    demo_personas,
    demo_tenant,
    og_image,
    canonical,
}: Props) {
    const t = useTranslations();
    const tree = useTranslationTree();
    const currency = commission?.currency ?? 'HUF';

    const c = tree<VerticalContent>(`verticals.${vertical}`);

    // The controller 404s a vertical with no copy, so this is unreachable in
    // practice. Kept because the alternative to a guard here is a page of
    // `undefined`s if that ever stops being true.
    if (c === null) {
        return null;
    }

    return (
        <MarketingLayout homeLink>
            <Head>
                <title>{c.meta_title}</title>
                <meta name="description" content={c.meta_description} />
                {/*
                    ⚠️ Canonical, and it matters more here than on the home page:
                    the traffic arrives from ads carrying `utm_*`, and without
                    this every campaign would be indexed as a separate page
                    competing with the others for the same phrase.
                */}
                <link rel="canonical" href={canonical} />
                <meta property="og:type" content="website" />
                <meta property="og:title" content={c.meta_title} />
                <meta property="og:description" content={c.meta_description} />
                <meta property="og:url" content={canonical} />
                {/*
                    The platform card, not a screenshot of this tenant: docs/22
                    §4 asks for one cut from the demo, and there is no browser in
                    this project to cut it with. A generic slot4u card is a
                    smaller loss than a stale one nobody can refresh.
                */}
                <meta property="og:image" content={og_image} />
                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta name="twitter:card" content="summary_large_image" />
                <meta name="twitter:image" content={og_image} />

                {/* SoftwareApplication (docs/22 §4). The FAQPage half is emitted
                    by the Faq component, beside the questions it describes. */}
                <script
                    type="application/ld+json"
                    dangerouslySetInnerHTML={{
                        __html: applicationJsonLd(c, canonical, commission),
                    }}
                />
            </Head>

            {/* 1 — hero. Navy, the grid and the glow, exactly as the home page
                opens: this is the same product and the visitor should be able to
                tell at a glance. The LCP element is the H1, which the server has
                already rendered. */}
            <section className="relative isolate overflow-hidden bg-navy text-canvas">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 opacity-10"
                    style={{
                        backgroundImage:
                            'linear-gradient(to right, var(--ice) 1px, transparent 1px), linear-gradient(to bottom, var(--ice) 1px, transparent 1px)',
                        backgroundSize: '32px 32px',
                    }}
                />
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-32 -right-24 h-[28rem] w-[28rem] rounded-full opacity-20 blur-3xl"
                    style={{
                        background:
                            'radial-gradient(circle, var(--ice) 0%, transparent 70%)',
                    }}
                />

                <div className="relative mx-auto grid w-full max-w-5xl gap-12 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-[7fr_5fr] lg:items-center">
                    <div>
                        <span className="inline-flex rounded-full border border-canvas/25 px-3 py-1 text-xs text-canvas/80">
                            {c.eyebrow}
                        </span>

                        {/* The accent falls in the MIDDLE of this headline, not
                            at its end as on the home page — hence three parts.
                            The highlighted word is the thing the visitor's
                            customer actually does. */}
                        <h1 className="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                            {c.title_lead}{' '}
                            <span className="text-highlight">
                                {c.title_accent}
                            </span>{' '}
                            {c.title_tail}
                        </h1>

                        <p className="mt-6 max-w-xl text-lg text-canvas/75">
                            {c.lead}
                        </p>

                        <div className="mt-10 flex flex-wrap items-center gap-3">
                            <a
                                href="/register"
                                onClick={() => trackLead({ vertical })}
                                className="ease-brand rounded-[10px] bg-highlight px-6 py-3 font-medium text-highlight-foreground transition-transform duration-200 hover:-translate-y-px focus-visible:ring-2 focus-visible:ring-ice focus-visible:outline-none"
                            >
                                {c.cta_primary}
                            </a>
                            {/* Scrolls to the live demo rather than leaving for
                                the tenant: docs/22 §4 asks for two clicks to a
                                running demo, and this is the first of them. */}
                            <a
                                href="#demo"
                                className="ease-brand rounded-[10px] border border-canvas/30 px-6 py-3 font-medium text-canvas transition-colors duration-200 hover:border-canvas/60 focus-visible:ring-2 focus-visible:ring-ice focus-visible:outline-none"
                            >
                                {c.cta_secondary}
                            </a>
                        </div>

                        <p className="mt-4 text-sm text-canvas/60">
                            {c.cta_caption}
                        </p>
                    </div>

                    <div className="relative flex justify-center lg:justify-end">
                        <HeroSlotPreview
                            title={c.widget.title}
                            day={c.widget.day}
                            slots={WORKSHOP_SLOTS}
                        />
                    </div>
                </div>
            </section>

            {/* 2 — the three pains, in the trade's own words. */}
            <IconRow title={c.pains.title} items={c.pains.items} />

            {/* 3 — how it works, three steps. Numbered rather than illustrated
                with screenshots: docs/22 §4 asks for three shots of the demo
                tenant, and the same call was made on the home page (HowItWorks)
                — a picture of an admin screen goes stale silently and the next
                person to change that screen cannot retake it. */}
            <section className="border-y border-line bg-canvas">
                <div className="mx-auto w-full max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                    <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                        {c.steps.title}
                    </h2>
                    <p className="mt-3 max-w-2xl text-ink-muted">
                        {c.steps.lead}
                    </p>

                    <ol className="mt-10 grid gap-8 sm:grid-cols-3">
                        {c.steps.items.map((step, index) => (
                            <li key={step.title}>
                                <span className="flex size-9 items-center justify-center rounded-full bg-navy font-mono text-sm text-canvas">
                                    {index + 1}
                                </span>
                                <p className="mt-4 font-medium text-ink">
                                    {step.title}
                                </p>
                                <p className="mt-1 text-sm text-ink-muted">
                                    {step.body}
                                </p>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            {/* 4 — six features, on the brand band, as on the home page. */}
            <div id="funkciok">
                <IconRow
                    title={c.features.title}
                    items={c.features.items}
                    banded
                />
            </div>

            {/* 5 — the live demo. Narrowed to this vertical's tenant, with no
                card list: the visitor did not come here to choose a business.
                Renders nothing at all when that tenant is not seeded. */}
            <TryItLive
                personas={demo_personas}
                only={demo_tenant ?? undefined}
                title={c.demo.title}
                lead={c.demo.lead}
                caption={c.demo.caption}
                vertical={vertical}
            />

            {/* 6 — what it does NOT do. Unusual on a landing page and asked for
                explicitly (docs/22 §4 row 6, §7.2): the workshop owner's actual
                fear is being sold an all-knowing system they have to learn. */}
            <section className="border-y border-line bg-canvas">
                <div className="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6 sm:py-20">
                    <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                        {c.not_for.title}
                    </h2>
                    <p className="mt-3 text-ink-muted">{c.not_for.lead}</p>

                    <ul className="mt-8 space-y-3">
                        {c.not_for.items.map((item) => (
                            <li
                                key={item}
                                className="flex items-start gap-3 text-ink"
                            >
                                <span
                                    aria-hidden
                                    className="mt-2 size-1.5 shrink-0 rounded-full bg-ink-muted"
                                />
                                {item}
                            </li>
                        ))}
                    </ul>

                    <p className="mt-6 rounded-[14px] border border-line bg-card p-5 text-ink-muted">
                        {c.not_for.footnote}
                    </p>
                </div>
            </section>

            {/* 7 — the price. ⚠️ docs/22 §4 asks for a highlighted "Közepes
                csomag" here; the tiered packages are gone (CLAUDE.md, docs/10),
                so this quotes the model the platform actually bills on, with the
                trade-specific framing docs/22 §7.4 asked for. Same figures as
                the home page, from the same service — a landing that advertised
                its own numbers would be the one that goes stale. */}
            <section id="arazas" className="border-b border-line bg-canvas">
                <div className="mx-auto w-full max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                    <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                        {c.pricing.title}
                    </h2>
                    <p className="mt-3 max-w-2xl text-ink-muted">
                        {c.pricing.lead}
                    </p>

                    {commission !== null && (
                        <>
                            <dl className="mt-10 grid gap-4 sm:grid-cols-3">
                                <div className="rounded-[14px] border border-line bg-card p-5">
                                    <dt className="text-sm text-ink-muted">
                                        {t('welcome.pricing_free', {
                                            amount: formatMoney(
                                                commission.free_threshold_minor,
                                                currency,
                                            ),
                                        })}
                                    </dt>
                                    <dd className="mt-2 font-mono text-2xl">
                                        {t('welcome.pricing_free_value')}
                                    </dd>
                                </div>

                                <div className="rounded-[14px] border border-navy bg-navy p-5 text-canvas">
                                    <dt className="text-sm text-canvas/70">
                                        {t('welcome.pricing_rate')}
                                    </dt>
                                    <dd className="mt-2 font-mono text-2xl">
                                        {t('welcome.pricing_rate_value', {
                                            rate: formatRate(
                                                commission.rate_bps,
                                            ),
                                        })}
                                    </dd>
                                </div>

                                {commission.monthly_cap_minor !== null && (
                                    <div className="rounded-[14px] border border-line bg-card p-5">
                                        <dt className="text-sm text-ink-muted">
                                            {t('welcome.pricing_cap')}
                                        </dt>
                                        <dd className="mt-2 font-mono text-2xl">
                                            {t('welcome.pricing_cap_value', {
                                                amount: formatMoney(
                                                    commission.monthly_cap_minor,
                                                    currency,
                                                ),
                                            })}
                                        </dd>
                                    </div>
                                )}
                            </dl>

                            <p className="mt-6 text-xs text-ink-muted">
                                {t('welcome.pricing_note')}
                            </p>
                        </>
                    )}
                </div>
            </section>

            {/* 8 — the questions, with their FAQPage JSON-LD. */}
            <Faq title={c.faq.title} items={c.faq.items} />

            {/* 9 — the closing CTA. The page's second and last yellow button
                (docs/21 §1: one highlight per screen). */}
            <section className="relative isolate overflow-hidden bg-navy text-canvas">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 opacity-10"
                    style={{
                        backgroundImage:
                            'linear-gradient(to right, var(--ice) 1px, transparent 1px), linear-gradient(to bottom, var(--ice) 1px, transparent 1px)',
                        backgroundSize: '32px 32px',
                    }}
                />
                <div
                    aria-hidden
                    className="pointer-events-none absolute -bottom-40 left-1/2 h-[24rem] w-[24rem] -translate-x-1/2 rounded-full opacity-20 blur-3xl"
                    style={{
                        background:
                            'radial-gradient(circle, var(--ice) 0%, transparent 70%)',
                    }}
                />

                <div className="relative mx-auto w-full max-w-5xl px-4 py-16 text-center sm:px-6 sm:py-24">
                    <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                        {c.closing.title}
                    </h2>
                    <p className="mt-3 text-canvas/75">{c.closing.lead}</p>
                    <div className="mt-8 flex justify-center">
                        <a
                            href="/register"
                            onClick={() => trackLead({ vertical })}
                            className="ease-brand rounded-[10px] bg-highlight px-6 py-3 font-medium text-highlight-foreground transition-transform duration-200 hover:-translate-y-px focus-visible:ring-2 focus-visible:ring-ice focus-visible:outline-none"
                        >
                            {c.cta_primary}
                        </a>
                    </div>
                    <p className="mt-4 text-sm text-canvas/60">
                        {c.cta_caption}
                    </p>
                </div>
            </section>
        </MarketingLayout>
    );
}

/**
 * A row of icon-led blocks — the pains and the features are the same shape, and
 * building them twice would be two places for the hover step to drift.
 *
 * `banded` puts it on the `brand-100` band the home page's feature grid uses, so
 * two adjacent rows do not read as one long list.
 */
function IconRow({
    title,
    items,
    banded = false,
}: {
    title: string;
    items: IconItem[];
    banded?: boolean;
}) {
    const reduced = useReducedMotion();
    const [ref, seen] = useInView<HTMLDivElement>();

    const revealed = seen || reduced;

    return (
        <section
            className={`border-y border-line ${banded ? 'bg-brand-100/50' : 'bg-canvas'}`}
        >
            <div className="mx-auto w-full max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {title}
                </h2>

                <div
                    ref={ref}
                    className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3"
                >
                    {items.map((item, index) => {
                        const Icon = ICONS[item.icon];

                        return (
                            <div
                                key={item.title}
                                className="group transition-all duration-500"
                                style={{
                                    transitionDelay: `${stagger(index, 80, reduced)}ms`,
                                    opacity: revealed ? 1 : 0,
                                    transform: revealed
                                        ? 'none'
                                        : 'translateY(12px)',
                                }}
                            >
                                {Icon !== undefined && (
                                    <div className="ease-brand flex size-10 items-center justify-center rounded-[10px] bg-brand-100 transition-colors duration-200 group-hover:bg-brand-200">
                                        <Icon
                                            className="ease-brand size-5 text-brand transition-transform duration-200 group-hover:scale-105"
                                            strokeWidth={1.75}
                                            aria-hidden
                                        />
                                    </div>
                                )}
                                <p className="mt-4 font-medium text-ink">
                                    {item.title}
                                </p>
                                <p className="mt-1 text-sm text-ink-muted">
                                    {item.body}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

/**
 * schema.org `SoftwareApplication` for this vertical (docs/22 §4).
 *
 * The offer is quoted only when the platform has published commission settings:
 * a price in structured data that does not match the page is worse than no price
 * at all, and search engines act on this one.
 */
function applicationJsonLd(
    content: VerticalContent,
    canonical: string,
    commission: CommissionTerms | null,
): string {
    return JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'SoftwareApplication',
        name: content.meta_title,
        description: content.meta_description,
        url: canonical,
        applicationCategory: 'BusinessApplication',
        operatingSystem: 'Web',
        ...(commission === null
            ? {}
            : {
                  offers: {
                      '@type': 'Offer',
                      // Free to start, and that is the literal truth of the
                      // model: nothing is charged below the monthly threshold.
                      price: '0',
                      priceCurrency: commission.currency,
                  },
              }),
    });
}
