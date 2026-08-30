import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatMoney, statusBadgeClass } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';

type TopTenant = {
    tenant_id: number;
    tenant_name: string;
    tenant_slug: string;
    tenant_status: string;
    turnover_minor: number;
    commission_minor: number;
    correction_minor: number;
    net_minor: number;
    cap_reached: boolean;
};

type OverdueInvoice = {
    invoice_id: number;
    tenant_id: number;
    tenant_name: string | null;
    tenant_slug: string | null;
    period: string;
    total_gross_minor: number;
    currency: string;
    due_at: string;
    suspend_at: string;
    days_until_suspension: number;
};

type Statistics = {
    period: string;
    currency: string;
    configured: boolean;
    free_threshold_minor: number | null;
    monthly_cap_minor: number | null;
    turnover_total_minor: number;
    commission_accrued_minor: number;
    correction_total_minor: number;
    commission_net_minor: number;
    commission_open_minor: number;
    commission_invoiced_minor: number;
    commission_paid_minor: number;
    commission_overdue_minor: number;
    active_tenants: number;
    tenants_with_turnover: number;
    paying_tenants: number;
    stuck_tenants: number;
    cap_reached_tenants: number;
    top_tenants: TopTenant[];
    overdue_invoices: OverdueInvoice[];
    overdue_count: number;
    overdue_total_minor: number;
};

type DashboardProps = {
    statistics: Statistics;
    filters: { period: string | null };
    grace_days: number;
};

/**
 * Superadmin dashboard (SLO-123, docs/10 §10): the platform's commission
 * business view — monthly revenue (MRR proxy), the activation funnel, top
 * tenants and suspension risk — plus the navigation hub. All figures are
 * computed server-side; this page only renders and lets the superadmin pick the
 * reporting month.
 */
export default function SuperDashboard({ statistics, filters }: DashboardProps) {
    const t = useTranslations();
    const currency = statistics.currency;

    const [period, setPeriod] = useState(filters.period ?? '');

    function applyFilter(event: FormEvent) {
        event.preventDefault();
        router.get('/', { period }, { preserveState: true, preserveScroll: true, replace: true });
    }

    const stuckRatio =
        statistics.tenants_with_turnover > 0
            ? new Intl.NumberFormat('hu-HU', {
                  style: 'percent',
                  maximumFractionDigits: 0,
              }).format(statistics.stuck_tenants / statistics.tenants_with_turnover)
            : '—';

    return (
        <AppLayout platformAccent>
            <Head title={t('super.dashboard.title')} />

            <div className="mx-auto w-full max-w-6xl">
                {/* Header + period filter */}
                <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <span className="rounded-full border border-border px-3 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {t('super.dashboard.badge')}
                        </span>
                        <h1 className="mt-3 text-2xl font-semibold tracking-tight">
                            {t('super.dashboard.title')}
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {t('super.dashboard.subtitle')}
                        </p>
                    </div>

                    <form onSubmit={applyFilter} className="flex items-end gap-2">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="period">{t('super.dashboard.period_label')}</Label>
                            <Input
                                id="period"
                                value={period}
                                onChange={(e) => setPeriod(e.target.value)}
                                placeholder={t('super.dashboard.period_placeholder')}
                                className="w-32"
                            />
                        </div>
                        <Button type="submit">{t('super.dashboard.period_apply')}</Button>
                    </form>
                </div>

                <p className="mb-4 text-xs text-muted-foreground">
                    {t('super.dashboard.period_hint', { period: statistics.period })}
                </p>

                {!statistics.configured ? (
                    <div className="mb-6 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-500">
                        {t('super.dashboard.not_configured')}
                    </div>
                ) : null}

                {/* Revenue */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.dashboard.revenue_heading')}
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="rounded-xl border border-border bg-card p-5">
                            <div className="text-xs text-muted-foreground">
                                {t('super.dashboard.revenue_net')}
                            </div>
                            <div className="mt-1 text-3xl font-semibold tabular-nums">
                                {formatMoney(statistics.commission_net_minor, currency)}
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {t('super.dashboard.revenue_net_hint')}
                            </p>

                            {/* Gross accrual and the credits that netted it down (§8.2) —
                                shown only when a credit actually moved the number. */}
                            {statistics.correction_total_minor !== 0 ? (
                                <>
                                    <div className="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-xs">
                                        <span>
                                            {t('super.dashboard.revenue_accrued')}:{' '}
                                            <span className="tabular-nums">
                                                {formatMoney(
                                                    statistics.commission_accrued_minor,
                                                    currency,
                                                )}
                                            </span>
                                        </span>
                                        <span className="text-amber-400">
                                            {t('super.dashboard.revenue_correction')}:{' '}
                                            <span className="tabular-nums">
                                                {formatMoney(
                                                    statistics.correction_total_minor,
                                                    currency,
                                                )}
                                            </span>
                                        </span>
                                    </div>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {t('super.dashboard.revenue_correction_hint')}
                                    </p>
                                </>
                            ) : null}

                            <div className="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-xs">
                                <span className="text-muted-foreground">
                                    {t('super.dashboard.revenue_of_which')}
                                </span>
                                <span>
                                    {t('super.dashboard.revenue_open')}:{' '}
                                    <span className="tabular-nums">
                                        {formatMoney(statistics.commission_open_minor, currency)}
                                    </span>
                                </span>
                                <span>
                                    {t('super.dashboard.revenue_invoiced')}:{' '}
                                    <span className="tabular-nums text-blue-400">
                                        {formatMoney(statistics.commission_invoiced_minor, currency)}
                                    </span>
                                </span>
                                <span>
                                    {t('super.dashboard.revenue_paid')}:{' '}
                                    <span className="tabular-nums text-green-400">
                                        {formatMoney(statistics.commission_paid_minor, currency)}
                                    </span>
                                </span>
                                <span>
                                    {t('super.dashboard.revenue_overdue')}:{' '}
                                    <span className="tabular-nums text-red-400">
                                        {formatMoney(statistics.commission_overdue_minor, currency)}
                                    </span>
                                </span>
                            </div>
                        </div>
                        <div className="rounded-xl border border-border bg-card p-5">
                            <div className="text-xs text-muted-foreground">
                                {t('super.dashboard.revenue_turnover')}
                            </div>
                            <div className="mt-1 text-3xl font-semibold tabular-nums">
                                {formatMoney(statistics.turnover_total_minor, currency)}
                            </div>
                        </div>
                    </div>
                </section>

                {/* Activation funnel */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.dashboard.funnel_heading')}
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <FunnelTile
                            label={t('super.dashboard.funnel_active')}
                            value={statistics.active_tenants}
                            hint={t('super.dashboard.funnel_active_hint')}
                        />
                        <FunnelTile
                            label={t('super.dashboard.funnel_with_turnover')}
                            value={statistics.tenants_with_turnover}
                        />
                        <FunnelTile
                            label={t('super.dashboard.funnel_paying')}
                            value={statistics.paying_tenants}
                            accent="text-green-400"
                            hint={t('super.dashboard.funnel_paying_hint')}
                        />
                        <FunnelTile
                            label={t('super.dashboard.funnel_stuck')}
                            value={statistics.stuck_tenants}
                            accent="text-amber-400"
                            hint={t('super.dashboard.funnel_stuck_hint', { ratio: stuckRatio })}
                        />
                    </div>
                    <div className="mt-4 rounded-xl border border-border bg-card px-5 py-3 text-sm">
                        {t('super.dashboard.funnel_cap_reached')}:{' '}
                        <span className="font-semibold tabular-nums">{statistics.cap_reached_tenants}</span>
                    </div>
                </section>

                {/* Top tenants */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.dashboard.top_heading')}
                    </h2>
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        {t('super.dashboard.top_col_tenant')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('super.dashboard.top_col_status')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.dashboard.top_col_turnover')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('super.dashboard.top_col_commission')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {statistics.top_tenants.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            {t('super.dashboard.top_empty')}
                                        </td>
                                    </tr>
                                ) : (
                                    statistics.top_tenants.map((tenant) => (
                                        <tr key={tenant.tenant_id} className="border-t border-border">
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/tenants/${tenant.tenant_id}`}
                                                    className="hover:underline"
                                                >
                                                    {tenant.tenant_name}
                                                </Link>
                                                {tenant.cap_reached ? (
                                                    <span className="ml-2 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-400">
                                                        {t('super.dashboard.top_cap_badge')}
                                                    </span>
                                                ) : null}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusBadgeClass(tenant.tenant_status)}`}
                                                >
                                                    {t(`tenant_status.${tenant.tenant_status}`)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {formatMoney(tenant.turnover_minor, currency)}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {formatMoney(tenant.net_minor, currency)}
                                                {/* A credited period bills less than it accrued (§8.2). */}
                                                {tenant.correction_minor !== 0 ? (
                                                    <div className="text-xs text-amber-400">
                                                        {t('super.dashboard.top_correction', {
                                                            amount: formatMoney(
                                                                tenant.correction_minor,
                                                                currency,
                                                            ),
                                                        })}
                                                    </div>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* Suspension risk */}
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.dashboard.risk_heading')}
                    </h2>
                    {statistics.overdue_invoices.length === 0 ? (
                        <div className="rounded-xl border border-border bg-card px-5 py-6 text-sm text-muted-foreground">
                            {t('super.dashboard.risk_empty')}
                        </div>
                    ) : (
                        <>
                            <p className="mb-3 text-sm text-red-400">
                                {t('super.dashboard.risk_summary', {
                                    count: statistics.overdue_count,
                                    total: formatMoney(statistics.overdue_total_minor, currency),
                                })}
                            </p>
                            <div className="overflow-x-auto rounded-xl border border-border">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">
                                                {t('super.dashboard.risk_col_tenant')}
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                {t('super.dashboard.risk_col_period')}
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                {t('super.dashboard.risk_col_gross')}
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                {t('super.dashboard.risk_col_due')}
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                {t('super.dashboard.risk_col_suspend')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {statistics.overdue_invoices.map((invoice) => (
                                            <tr
                                                key={invoice.invoice_id}
                                                className="border-t border-border"
                                            >
                                                <td className="px-4 py-3">
                                                    {invoice.tenant_name ? (
                                                        <Link
                                                            href={`/tenants/${invoice.tenant_id}`}
                                                            className="hover:underline"
                                                        >
                                                            {invoice.tenant_name}
                                                        </Link>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 tabular-nums">
                                                    {invoice.period}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {formatMoney(invoice.total_gross_minor, invoice.currency)}
                                                </td>
                                                <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                                    {formatDate(invoice.due_at)}
                                                </td>
                                                <td className="px-4 py-3 whitespace-nowrap">
                                                    <span className="text-muted-foreground">
                                                        {formatDate(invoice.suspend_at)}
                                                    </span>
                                                    <span className="ml-2 text-red-400">
                                                        {invoice.days_until_suspension > 0
                                                            ? t('super.dashboard.risk_suspend_in', {
                                                                  days: invoice.days_until_suspension,
                                                              })
                                                            : t('super.dashboard.risk_suspend_due')}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>

                {/* Navigation hub */}
                <section>
                    <h2 className="mb-3 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        {t('super.dashboard.nav_heading')}
                    </h2>
                    <div className="flex flex-wrap gap-3">
                        <Button asChild variant="outline">
                            <Link href="/tenants">{t('super.dashboard.tenants_link')}</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/statistics">{t('super.dashboard.statistics_link')}</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/commission">{t('super.dashboard.commission_link')}</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/commission-invoices">
                                {t('super.dashboard.invoices_link')}
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/audit-logs">{t('super.dashboard.audit_link')}</Link>
                        </Button>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}

function FunnelTile({
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
