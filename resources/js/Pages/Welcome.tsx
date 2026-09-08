import { Head } from '@inertiajs/react';

import HeroSlotPreview from '@/components/HeroSlotPreview';
import AssuranceStrip from '@/components/landing/AssuranceStrip';
import HowItWorks from '@/components/landing/HowItWorks';
import FeatureGrid from '@/components/landing/FeatureGrid';
import Faq from '@/components/landing/Faq';
import ProductShowcase from '@/components/landing/ProductShowcase';
import TryItLive from '@/components/landing/TryItLive';
import MarketingLayout from '@/Layouts/MarketingLayout';
import { useTranslations } from '@/lib/i18n';
import { formatMoney, formatRate } from '@/lib/format';
import type { DemoPersona } from '@/types';

/**
 * The commission terms as the server resolved them (SLO-50). Null when the
 * platform has published no settings version — the page then states the model
 * without quoting figures rather than inventing them.
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
    commission: CommissionTerms | null;
    demo_url: string | null;
    /**
     * The demo tenants a visitor can walk into (SLO-192). Empty on an
     * installation with nothing seeded — the section then renders nothing at all
     * rather than a dead first click.
     */
    demo_personas: DemoPersona[];
    /** Absolute URL of the link-preview card — see HomeController. */
    og_image: string;
};

function Section({
    id,
    title,
    lead,
    children,
}: {
    id?: string;
    title: string;
    lead?: string;
    children: React.ReactNode;
}) {
    return (
        <section id={id} className="border-t border-border">
            <div className="mx-auto w-full max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                    {title}
                </h2>
                {lead !== undefined && (
                    <p className="mt-3 max-w-2xl text-muted-foreground">
                        {lead}
                    </p>
                )}
                <div className="mt-10">{children}</div>
            </div>
        </section>
    );
}

function Tile({ title, hint }: { title: string; hint: string }) {
    return (
        <div className="rounded-lg border border-border bg-card p-5">
            <h3 className="font-medium">{title}</h3>
            <p className="mt-2 text-sm text-muted-foreground">{hint}</p>
        </div>
    );
}

export default function Welcome({
    commission,
    demo_url,
    demo_personas,
    og_image,
}: Props) {
    const t = useTranslations();
    const currency = commission?.currency ?? 'HUF';

    return (
        <MarketingLayout>
            <Head>
                <title>{t('welcome.meta_title')}</title>
                <meta
                    name="description"
                    content={t('welcome.meta_description')}
                />
                {/* Open Graph, so a link pasted into a chat renders as something
                    other than a bare URL. The card image landed with the visual
                    identity (SLO-170); `summary_large_image` rather than
                    `summary`, because a 1200×630 card shown in the small square
                    slot is centre-cropped into an unreadable detail. */}
                <meta property="og:type" content="website" />
                <meta property="og:title" content={t('welcome.meta_title')} />
                <meta
                    property="og:description"
                    content={t('welcome.meta_description')}
                />
                <meta property="og:image" content={og_image} />
                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta name="twitter:card" content="summary_large_image" />
                <meta name="twitter:image" content={og_image} />
            </Head>

            {/*
                Hero (docs/21 §2 row 1). Navy, with a 32px grid and a glow in the
                top right — the "high-tech" half of the identity, and the only
                place on the page that gets a wow moment.

                ⚠️ The LCP element is the H1, not an image: it is text the server
                already rendered, so it paints with the document. Everything
                decorative here is CSS — no image request stands between the
                visitor and the headline.
            */}
            <section className="relative isolate overflow-hidden bg-navy text-canvas">
                {/* 32px grid, ice at 10% (docs/21 §1: ice is a hairline, never a fill). */}
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
                            {t('welcome.badge')}
                        </span>

                        {/*
                            The headline keeps the message that is already tested
                            and already true (WelcomeTest): this product costs
                            nothing until it earns. docs/21 supplies the SHAPE —
                            eyebrow, a headline with one word in the accent, lead,
                            two buttons, a caption — and its own copy is a
                            suggestion inside a Claude Design prompt, not the
                            page's voice. Swapping in a softer line would have
                            traded the differentiator for a slogan.

                            The highlight sits on the phrase that IS the offer.
                        */}
                        <h1 className="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                            {t('welcome.title_lead')}{' '}
                            <span className="text-highlight">
                                {t('welcome.title_accent')}
                            </span>
                        </h1>

                        <p className="mt-6 max-w-xl text-lg text-canvas/75">
                            {t('welcome.subtitle')}
                        </p>

                        <div className="mt-10 flex flex-wrap items-center gap-3">
                            {/* Plain anchors, not Inertia <Link>: /register is a
                                Fortify route outside the Inertia page graph. */}
                            <a
                                href="/register"
                                className="ease-brand rounded-[10px] bg-highlight px-6 py-3 font-medium text-highlight-foreground transition-transform duration-200 hover:-translate-y-px focus-visible:ring-2 focus-visible:ring-ice focus-visible:outline-none"
                            >
                                {t('welcome.cta_primary')}
                            </a>
                            {demo_url !== null && (
                                <a
                                    href={demo_url}
                                    className="ease-brand rounded-[10px] border border-canvas/30 px-6 py-3 font-medium text-canvas transition-colors duration-200 hover:border-canvas/60 focus-visible:ring-2 focus-visible:ring-ice focus-visible:outline-none"
                                >
                                    {t('welcome.cta_secondary')}
                                </a>
                            )}
                        </div>

                        <p className="mt-4 text-sm text-canvas/60">
                            {t('welcome.cta_caption')}
                        </p>
                    </div>

                    {/*
                        The widget, in the light, on the dark. ⚠️ The flying sloth
                        belongs behind it (docs/21 §2) — that asset is SLO-202 and
                        does not exist yet, so the column is built to take it
                        without moving: the illustration will sit absolutely
                        behind this card, which is why the wrapper is `relative`.
                    */}
                    <div className="relative flex justify-center lg:justify-end">
                        <HeroSlotPreview />
                    </div>
                </div>
            </section>

            {/* docs/21 §2 row 2 — assurance, not customer logos (see the
                component's own note on why). */}
            <AssuranceStrip />

            {/* row 3 */}
            <HowItWorks />

            {/* Pricing — the reason this page exists */}
            <Section
                id="arazas"
                title={t('welcome.pricing_title')}
                lead={t('welcome.pricing_lead')}
            >
                {commission !== null && (
                    <>
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <div className="rounded-[14px] border border-line bg-card p-5">
                                <dt className="text-sm text-ink-muted">
                                    {t('welcome.pricing_free', {
                                        amount: formatMoney(
                                            commission.free_threshold_minor,
                                            currency,
                                        ),
                                    })}
                                </dt>
                                {/* Figures wear the mono face (docs/21 §1). */}
                                <dd className="mt-2 font-mono text-2xl">
                                    {t('welcome.pricing_free_value')}
                                </dd>
                            </div>

                            {/* The rate is the number that decides whether
                                somebody signs up, so it is the one card in navy
                                — the emphasis docs/21 gives a highlighted tier,
                                without a package to highlight. */}
                            <div className="rounded-[14px] border border-navy bg-navy p-5 text-canvas">
                                <dt className="text-sm text-canvas/70">
                                    {t('welcome.pricing_rate')}
                                </dt>
                                <dd className="mt-2 font-mono text-2xl">
                                    {t('welcome.pricing_rate_value', {
                                        rate: formatRate(commission.rate_bps),
                                    })}
                                </dd>
                                <p className="mt-2 text-sm text-canvas/70">
                                    {t('welcome.pricing_rate_hint', {
                                        rate: formatRate(
                                            commission.rate_with_integration_bps,
                                        ),
                                    })}
                                </p>
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
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {t('welcome.pricing_cap_hint')}
                                    </p>
                                </div>
                            )}
                        </dl>

                        <div className="mt-6 rounded-lg border border-border bg-muted/30 p-5">
                            <h3 className="font-medium">
                                {t('welcome.pricing_example_title')}
                            </h3>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {t('welcome.pricing_example', {
                                    turnover: formatMoney(
                                        commission.example_turnover_minor,
                                        currency,
                                    ),
                                    taxable: formatMoney(
                                        commission.example_billable_base_minor,
                                        currency,
                                    ),
                                    rate: formatRate(commission.rate_bps),
                                    fee: formatMoney(
                                        commission.example_commission_minor,
                                        currency,
                                    ),
                                })}
                            </p>
                        </div>
                    </>
                )}

                <p className="mt-6 text-sm text-muted-foreground">
                    {t('welcome.pricing_cancel')}
                </p>
                {commission !== null && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        {t('welcome.pricing_note')}
                    </p>
                )}
            </Section>

            {/* The five booking modes */}
            <Section
                title={t('welcome.features_title')}
                lead={t('welcome.features_lead')}
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {[
                        'duration_based',
                        'event_based',
                        'resource_rental',
                        'no_time_slot',
                        'quote_request',
                    ].map((mode) => (
                        <Tile
                            key={mode}
                            title={t(`welcome.modes.${mode}`)}
                            hint={t(`welcome.modes.${mode}_hint`)}
                        />
                    ))}
                </div>
            </Section>

            {/* Who it is for */}
            <Section
                title={t('welcome.audience_title')}
                lead={t('welcome.audience_lead')}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    {['therapist', 'trainer', 'salon', 'rental'].map((who) => (
                        <Tile
                            key={who}
                            title={t(`welcome.audience.${who}`)}
                            hint={t(`welcome.audience.${who}_hint`)}
                        />
                    ))}
                </div>
            </Section>

            {/* docs/21 §2 row 4 — the feature grid, on its own brand-100 band.
                Six blocks in 2×3, grown from the four this section already had:
                the two additions are shipped features, not promises. */}
            <FeatureGrid />

            {/* row 5 */}
            <ProductShowcase />

            {/* row 6 — the live demo. The strongest thing on the page, and the
                only section whose content is a running copy of the product
                rather than a description of it (docs/21 §2.1). */}
            <TryItLive personas={demo_personas} />

            {/* row 9 */}
            <Faq />

            {/*
                Closing CTA (docs/21 §2 row 10) — navy, grid and glow, closing
                the page on the same note the hero opened it.

                ⚠️ Row 7 (testimonials) is deliberately absent. Without real,
                quotable, permitted customers it could only be filled with
                invented ones, and a fabricated review is worth less than the
                gap it fills. It returns when there are references — the section
                is not built here even as a placeholder, because a placeholder
                testimonial is a fabricated one nobody remembers to remove.
            */}
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
                        {t('welcome.closing_title')}
                    </h2>
                    <p className="mt-3 text-canvas/75">
                        {t('welcome.closing_lead')}
                    </p>
                    <div className="mt-8 flex flex-wrap justify-center gap-3">
                        {/* The page's second and last yellow CTA — the hero's
                            is the first. Nothing between them competes. */}
                        <a
                            href="/register"
                            className="ease-brand rounded-[10px] bg-highlight px-6 py-3 font-medium text-highlight-foreground transition-transform duration-200 hover:-translate-y-px focus-visible:ring-2 focus-visible:ring-ice focus-visible:outline-none"
                        >
                            {t('welcome.cta_primary')}
                        </a>
                        {demo_url !== null && (
                            <a
                                href={demo_url}
                                className="ease-brand rounded-[10px] border border-canvas/30 px-6 py-3 font-medium text-canvas transition-colors duration-200 hover:border-canvas/60 focus-visible:ring-2 focus-visible:ring-ice focus-visible:outline-none"
                            >
                                {t('welcome.footer_demo')}
                            </a>
                        )}
                    </div>
                    <p className="mt-4 text-sm text-canvas/60">
                        {t('welcome.cta_caption')}
                    </p>
                </div>
            </section>
        </MarketingLayout>
    );
}
