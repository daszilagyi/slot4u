import { Head } from '@inertiajs/react';

import MarketingLayout from '@/Layouts/MarketingLayout';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import { formatMoney, formatRate } from '@/lib/format';

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

export default function Welcome({ commission, demo_url }: Props) {
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
                    other than a bare URL. No og:image yet — that waits on the
                    visual identity (SLO-170); a broken image reference is worse
                    than none, because platforms cache the failure. */}
                <meta property="og:type" content="website" />
                <meta property="og:title" content={t('welcome.meta_title')} />
                <meta
                    property="og:description"
                    content={t('welcome.meta_description')}
                />
                <meta name="twitter:card" content="summary" />
            </Head>

            {/* Hero */}
            <div className="mx-auto w-full max-w-5xl px-4 py-20 sm:px-6 sm:py-28">
                <span className="inline-flex rounded-full border border-border px-3 py-1 text-xs text-muted-foreground">
                    {t('welcome.badge')}
                </span>
                <h1 className="mt-6 max-w-3xl text-4xl font-semibold tracking-tight sm:text-5xl">
                    {t('welcome.title')}
                </h1>
                <p className="mt-6 max-w-2xl text-lg text-muted-foreground">
                    {t('welcome.subtitle')}
                </p>
                <div className="mt-10 flex flex-wrap gap-3">
                    {/* Plain anchors, not Inertia <Link>: /register is a Fortify
                        route outside the Inertia page graph on this host. */}
                    <Button asChild size="lg">
                        <a href="/register">{t('welcome.cta_primary')}</a>
                    </Button>
                    {demo_url !== null && (
                        <Button asChild size="lg" variant="outline">
                            <a href={demo_url}>{t('welcome.cta_secondary')}</a>
                        </Button>
                    )}
                </div>
            </div>

            {/* Pricing — the reason this page exists */}
            <Section
                id="arazas"
                title={t('welcome.pricing_title')}
                lead={t('welcome.pricing_lead')}
            >
                {commission !== null && (
                    <>
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <div className="rounded-lg border border-border bg-card p-5">
                                <dt className="text-sm text-muted-foreground">
                                    {t('welcome.pricing_free', {
                                        amount: formatMoney(
                                            commission.free_threshold_minor,
                                            currency,
                                        ),
                                    })}
                                </dt>
                                <dd className="mt-2 text-2xl font-semibold">
                                    {t('welcome.pricing_free_value')}
                                </dd>
                            </div>

                            <div className="rounded-lg border border-border bg-card p-5">
                                <dt className="text-sm text-muted-foreground">
                                    {t('welcome.pricing_rate')}
                                </dt>
                                <dd className="mt-2 text-2xl font-semibold">
                                    {t('welcome.pricing_rate_value', {
                                        rate: formatRate(commission.rate_bps),
                                    })}
                                </dd>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {t('welcome.pricing_rate_hint', {
                                        rate: formatRate(
                                            commission.rate_with_integration_bps,
                                        ),
                                    })}
                                </p>
                            </div>

                            {commission.monthly_cap_minor !== null && (
                                <div className="rounded-lg border border-border bg-card p-5">
                                    <dt className="text-sm text-muted-foreground">
                                        {t('welcome.pricing_cap')}
                                    </dt>
                                    <dd className="mt-2 text-2xl font-semibold">
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

            {/* What every tenant gets */}
            <Section title={t('welcome.trust_title')}>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {['domain', 'privacy', 'notify', 'reports'].map((item) => (
                        <Tile
                            key={item}
                            title={t(`welcome.trust.${item}`)}
                            hint={t(`welcome.trust.${item}_hint`)}
                        />
                    ))}
                </div>
            </Section>

            {/* Closing call to action */}
            <section className="border-t border-border">
                <div className="mx-auto w-full max-w-5xl px-4 py-16 text-center sm:px-6 sm:py-20">
                    <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                        {t('welcome.closing_title')}
                    </h2>
                    <p className="mt-3 text-muted-foreground">
                        {t('welcome.closing_lead')}
                    </p>
                    <div className="mt-8 flex flex-wrap justify-center gap-3">
                        <Button asChild size="lg">
                            <a href="/register">{t('welcome.cta_primary')}</a>
                        </Button>
                        {demo_url !== null && (
                            <Button asChild size="lg" variant="ghost">
                                <a href={demo_url}>
                                    {t('welcome.footer_demo')}
                                </a>
                            </Button>
                        )}
                    </div>
                </div>
            </section>
        </MarketingLayout>
    );
}
