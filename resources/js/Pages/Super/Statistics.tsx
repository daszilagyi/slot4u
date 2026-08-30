import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney, formatRate } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type MonthlyStat = {
    period: string;
    signups: number;
    churned: number;
    net_change: number;
    tenants_at_start: number;
    tenants_at_end: number;
    churn_rate_bps: number | null;
    bookings: number;
    complete: boolean;
};

type TurnoverBand = {
    key: string;
    from_minor: number;
    to_minor: number | null;
    tenants: number;
    turnover_minor: number;
    commission_minor: number;
};

type Statistics = {
    period: string;
    timezone: string;
    months: number;
    configured: boolean;
    free_threshold_minor: number | null;
    currency: string;
    trial_tenants: number;
    active_tenants: number;
    suspended_tenants: number;
    archived_tenants: number;
    total_tenants: number;
    signups_total: number;
    churned_total: number;
    bookings_total: number;
    headline_churn_period: string | null;
    headline_churn_rate_bps: number | null;
    series: MonthlyStat[];
    turnover_bands: TurnoverBand[] | null;
    no_turnover_tenants: number;
};

type StatisticsProps = {
    statistics: Statistics;
    filters: { period: string | null; months: number | null };
    month_options: number[];
};

/**
 * Superadmin platform statistics (SLO-138): the tenant lifecycle, the monthly
 * growth / churn / booking series and the turnover distribution. Everything is
 * computed server-side; this page renders and lets the superadmin pick the month
 * and the window length.
 */
export default function SuperStatistics({ statistics, filters, month_options }: StatisticsProps) {
    const t = useTranslations();
    const currency = statistics.currency;

    const [period, setPeriod] = useState(filters.period ?? '');
    const [months, setMonths] = useState(String(filters.months ?? statistics.months));

    function applyFilter(event: FormEvent) {
        event.preventDefault();
        router.get(
            '/statistics',
            { period, months },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AppLayout platformAccent>
            <Head title={t('super.statistics.title')} />

            <div className="mx-auto w-full max-w-6xl">
                <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <span className="rounded-full border border-border px-3 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {t('super.statistics.badge')}
                        </span>
                        <h1 className="mt-3 text-2xl font-semibold tracking-tight">
                            {t('super.statistics.title')}
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {t('super.statistics.subtitle')}
                        </p>
                    </div>

                    <form onSubmit={applyFilter} className="flex flex-wrap items-end gap-2">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="period">{t('super.statistics.period_label')}</Label>
                            <Input
                                id="period"
                                value={period}
                                onChange={(e) => setPeriod(e.target.value)}
                                placeholder={t('super.statistics.period_placeholder')}
                                className="w-32"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="months">{t('super.statistics.months_label')}</Label>
                            <select
                                id="months"
                                value={months}
                                onChange={(e) => setMonths(e.target.value)}
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                {month_options.map((option) => (
                                    <option key={option} value={option}>
                                        {t('super.statistics.months_option', { count: option })}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button type="submit">{t('super.statistics.apply')}</Button>
                    </form>
                </div>

                <p className="mb-6 text-xs text-muted-foreground">
                    {t('super.statistics.timezone_hint', { timezone: statistics.timezone })}
                </p>

                {/* Lifecycle */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.statistics.lifecycle_heading')}
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatTile
                            label={t('super.statistics.lifecycle_trial')}
                            value={statistics.trial_tenants}
                            accent="text-blue-400"
                        />
                        <StatTile
                            label={t('super.statistics.lifecycle_active')}
                            value={statistics.active_tenants}
                            accent="text-green-400"
                        />
                        <StatTile
                            label={t('super.statistics.lifecycle_suspended')}
                            value={statistics.suspended_tenants}
                            accent="text-amber-400"
                            hint={t('super.statistics.lifecycle_suspended_hint')}
                        />
                        <StatTile
                            label={t('super.statistics.lifecycle_archived')}
                            value={statistics.archived_tenants}
                            hint={t('super.statistics.lifecycle_archived_hint')}
                        />
                    </div>
                    <p className="mt-3 text-xs text-muted-foreground">
                        {t('super.statistics.lifecycle_total', { count: statistics.total_tenants })}
                    </p>
                </section>

                {/* Growth & churn */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.statistics.growth_heading')}
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatTile
                            label={t('super.statistics.growth_signups')}
                            value={statistics.signups_total}
                            accent="text-green-400"
                        />
                        <StatTile
                            label={t('super.statistics.growth_churned')}
                            value={statistics.churned_total}
                            accent="text-red-400"
                        />
                        <StatTile
                            label={t('super.statistics.growth_net')}
                            value={statistics.signups_total - statistics.churned_total}
                        />
                        <div className="rounded-xl border border-border bg-card p-5">
                            <div className="text-xs text-muted-foreground">
                                {statistics.headline_churn_period
                                    ? t('super.statistics.growth_churn_rate', {
                                          period: statistics.headline_churn_period,
                                      })
                                    : t('super.statistics.growth_churn_rate', { period: '—' })}
                            </div>
                            <div className="mt-1 text-3xl font-semibold tabular-nums">
                                {statistics.headline_churn_rate_bps !== null
                                    ? formatRate(statistics.headline_churn_rate_bps)
                                    : '—'}
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {statistics.headline_churn_rate_bps !== null
                                    ? t('super.statistics.growth_churn_rate_hint')
                                    : t('super.statistics.growth_churn_unknown')}
                            </p>
                        </div>
                    </div>
                    <p className="mt-3 text-xs text-muted-foreground">
                        {t('super.statistics.churn_caveat')}
                    </p>
                </section>

                {/* Booking volume */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.statistics.bookings_heading')}
                    </h2>
                    <div className="rounded-xl border border-border bg-card p-5">
                        <div className="text-xs text-muted-foreground">
                            {t('super.statistics.bookings_total')}
                        </div>
                        <div className="mt-1 text-3xl font-semibold tabular-nums">
                            {statistics.bookings_total}
                        </div>
                        <p className="mt-2 mb-4 text-xs text-muted-foreground">
                            {t('super.statistics.bookings_hint')}
                        </p>
                        <BookingBars series={statistics.series} />
                    </div>
                </section>

                {/* Monthly table */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.statistics.series_heading')}
                    </h2>
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        {t('super.statistics.series_col_month')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.statistics.series_col_signups')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.statistics.series_col_churned')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.statistics.series_col_net')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.statistics.series_col_at_start')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.statistics.series_col_at_end')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.statistics.series_col_churn')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.statistics.series_col_bookings')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {/* Newest month first: the interesting row is the
                                    one closest to today, not the oldest. */}
                                {[...statistics.series].reverse().map((month) => (
                                    <tr key={month.period} className="border-t border-border">
                                        <td className="px-4 py-3 whitespace-nowrap tabular-nums">
                                            {month.period}
                                            {!month.complete ? (
                                                <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                    {t('super.statistics.series_running')}
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-green-400">
                                            {month.signups > 0 ? `+${month.signups}` : '0'}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-red-400">
                                            {month.churned > 0 ? `−${month.churned}` : '0'}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {month.net_change}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                            {month.tenants_at_start}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {month.tenants_at_end}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {month.churn_rate_bps !== null ? (
                                                formatRate(month.churn_rate_bps)
                                            ) : (
                                                <span
                                                    className="text-muted-foreground"
                                                    title={t(
                                                        'super.statistics.series_undefined_title',
                                                    )}
                                                >
                                                    {t('super.statistics.series_undefined')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {month.bookings}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* Turnover distribution */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.statistics.bands_heading', { period: statistics.period })}
                    </h2>

                    {statistics.turnover_bands === null || statistics.free_threshold_minor === null ? (
                        <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-500">
                            {t('super.statistics.bands_not_configured')}
                        </div>
                    ) : (
                        <>
                            <p className="mb-3 text-xs text-muted-foreground">
                                {t('super.statistics.bands_hint', {
                                    threshold: formatMoney(
                                        statistics.free_threshold_minor,
                                        currency,
                                    ),
                                })}
                            </p>
                            <div className="overflow-x-auto rounded-xl border border-border">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">
                                                {t('super.statistics.bands_col_band')}
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                {t('super.statistics.bands_col_tenants')}
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                {t('super.statistics.bands_col_turnover')}
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                {t('super.statistics.bands_col_commission')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr className="border-t border-border">
                                            <td className="px-4 py-3">
                                                {t('super.statistics.bands_none')}
                                                <div className="text-xs text-muted-foreground">
                                                    {t('super.statistics.bands_none_hint')}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {statistics.no_turnover_tenants}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                —
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                —
                                            </td>
                                        </tr>
                                        {statistics.turnover_bands.map((band) => (
                                            <tr key={band.key} className="border-t border-border">
                                                <td className="px-4 py-3">
                                                    {t(`super.statistics.bands_${band.key}`, {
                                                        from: formatMoney(band.from_minor, currency),
                                                        to:
                                                            band.to_minor !== null
                                                                ? formatMoney(
                                                                      band.to_minor,
                                                                      currency,
                                                                  )
                                                                : '',
                                                    })}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {band.tenants}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {formatMoney(band.turnover_minor, currency)}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {formatMoney(band.commission_minor, currency)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>

                <Button asChild variant="outline">
                    <Link href="/">{t('super.statistics.back')}</Link>
                </Button>
            </div>
        </AppLayout>
    );
}

function StatTile({
    label,
    value,
    hint,
    accent,
}: {
    label: string;
    value: number;
    hint?: string;
    accent?: string;
}) {
    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className={`mt-1 text-3xl font-semibold tabular-nums ${accent ?? ''}`}>{value}</div>
            {hint ? <p className="mt-2 text-xs text-muted-foreground">{hint}</p> : null}
        </div>
    );
}

/**
 * Monthly booking volume as bars, in the same CSS-only spirit as the tenant
 * report's RevenueBars (SLO-137) — one hue, no charting dependency. A month with
 * no bookings keeps a flat stub so a gap reads as "nothing happened" rather than
 * as missing data, and the running month is dimmed because it is still filling up.
 */
function BookingBars({ series }: { series: MonthlyStat[] }) {
    const t = useTranslations();
    const max = series.reduce((acc, point) => Math.max(acc, point.bookings), 0);
    const peak = series.find((point) => point.bookings === max && max > 0);

    if (max === 0) {
        return null;
    }

    return (
        <figure className="flex flex-col gap-3">
            <div
                // One image, not one per bar: a screen reader announcing every
                // month separately is noise — the table below carries the exact
                // figures.
                role="img"
                aria-label={t('super.statistics.bookings_chart_summary', {
                    months: series.length,
                    peak: peak
                        ? t('super.statistics.bookings_chart_point', {
                              period: peak.period,
                              count: peak.bookings,
                          })
                        : '',
                })}
                className="flex h-32 items-end gap-1"
            >
                {series.map((point) => {
                    const height =
                        point.bookings > 0
                            ? Math.max(2, Math.round((point.bookings / max) * 100))
                            : 0;

                    return (
                        <div key={point.period} className="flex h-full flex-1 items-end">
                            <div
                                title={t('super.statistics.bookings_chart_point', {
                                    period: point.period,
                                    count: point.bookings,
                                })}
                                style={height > 0 ? { height: `${height}%` } : undefined}
                                className={cn(
                                    'w-full rounded-t-[4px] transition-colors',
                                    height === 0
                                        ? 'h-[2px] bg-border'
                                        : point.complete
                                          ? 'bg-primary/80 hover:bg-primary'
                                          : 'bg-primary/40 hover:bg-primary/60',
                                )}
                            />
                        </div>
                    );
                })}
            </div>

            <div className="flex justify-between border-t border-border pt-2 text-xs text-muted-foreground">
                <span>{series[0]?.period}</span>
                <span>{series[series.length - 1]?.period}</span>
            </div>
        </figure>
    );
}
