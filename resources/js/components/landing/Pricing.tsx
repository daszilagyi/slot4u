import { formatMoney, formatRate } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';

/**
 * The commission terms as the server resolved them (SLO-50). Null when the
 * platform has published no settings version — the section then states the
 * model without quoting figures rather than inventing them.
 */
export type CommissionTerms = {
    free_threshold_minor: number;
    rate_bps: number;
    rate_with_integration_bps: number;
    monthly_cap_minor: number | null;
    currency: string;
    example_turnover_minor: number;
    example_billable_base_minor: number;
    example_commission_minor: number;
};

/**
 * Pricing — kept from the previous landing and redrawn in the new look
 * (SLO-229, Daniel 2026-09-13: the design has no pricing section, the product
 * does). Every figure is the configured one; WelcomeTest pins that the page
 * quotes the settings, not the copy.
 */
export default function Pricing({
    commission,
}: {
    commission: CommissionTerms | null;
}) {
    const t = useTranslations();
    const currency = commission?.currency ?? 'HUF';

    return (
        <section id="arazas" className="scroll-mt-20 bg-white">
            <div className="mx-auto w-full max-w-[1440px] px-4 py-16 sm:px-8 lg:px-14">
                <h2 className="text-3xl font-black text-navy sm:text-4xl">
                    {t('welcome.pricing_title')}
                </h2>
                <p className="mt-3 max-w-3xl text-base leading-relaxed text-ink-muted">
                    {t('welcome.pricing_lead')}
                </p>

                {commission !== null && (
                    <>
                        <dl className="mt-10 grid gap-5 md:grid-cols-3">
                            <div className="rounded-[18px] bg-canvas p-6">
                                <dt className="text-sm font-bold text-ink-muted">
                                    {t('welcome.pricing_free', {
                                        amount: formatMoney(
                                            commission.free_threshold_minor,
                                            currency,
                                        ),
                                    })}
                                </dt>
                                <dd className="mt-2 text-3xl font-black text-navy">
                                    {t('welcome.pricing_free_value')}
                                </dd>
                            </div>

                            {/* The rate decides whether somebody signs up, so it is
                                the one card in navy, with the figure in yellow. */}
                            <div className="rounded-[18px] bg-navy p-6 text-white shadow-[0_24px_60px_rgba(15,37,71,.2)]">
                                <dt className="text-sm font-bold text-mist">
                                    {t('welcome.pricing_rate')}
                                </dt>
                                <dd className="mt-2 text-3xl font-black text-highlight">
                                    {t('welcome.pricing_rate_value', {
                                        rate: formatRate(commission.rate_bps),
                                    })}
                                </dd>
                                <p className="mt-2 text-sm text-mist-200">
                                    {t('welcome.pricing_rate_hint', {
                                        rate: formatRate(
                                            commission.rate_with_integration_bps,
                                        ),
                                    })}
                                </p>
                            </div>

                            {commission.monthly_cap_minor !== null && (
                                <div className="rounded-[18px] bg-canvas p-6">
                                    <dt className="text-sm font-bold text-ink-muted">
                                        {t('welcome.pricing_cap')}
                                    </dt>
                                    <dd className="mt-2 text-3xl font-black text-navy">
                                        {t('welcome.pricing_cap_value', {
                                            amount: formatMoney(
                                                commission.monthly_cap_minor,
                                                currency,
                                            ),
                                        })}
                                    </dd>
                                    <p className="mt-2 text-sm text-ink-muted">
                                        {t('welcome.pricing_cap_hint')}
                                    </p>
                                </div>
                            )}
                        </dl>

                        <div className="mt-6 rounded-[18px] border-2 border-line p-6">
                            <h3 className="font-extrabold text-navy">
                                {t('welcome.pricing_example_title')}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-ink-muted">
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

                <p className="mt-6 text-sm text-ink-muted">
                    {t('welcome.pricing_cancel')}
                </p>
                {commission !== null && (
                    <p className="mt-2 text-xs text-ink-muted">
                        {t('welcome.pricing_note')}
                    </p>
                )}
            </div>
        </section>
    );
}
