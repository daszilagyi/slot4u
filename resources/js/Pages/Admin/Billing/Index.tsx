import { Head, router } from '@inertiajs/react';
import {
    CoinsIcon,
    DownloadIcon,
    GaugeIcon,
    GiftIcon,
    TrendingUpIcon,
} from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/components/admin/EmptyState';
import PageHeader from '@/components/admin/PageHeader';
import StatCard from '@/components/admin/StatCard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    billingStatusBadgeClass,
    formatDate,
    formatDateTime,
    formatMoney,
    formatRate,
} from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { Paginator } from '@/types';

type Overview = {
    period: string;
    currency: string;
    configured: boolean;
    status: string;
    turnover_minor: number;
    billable_base_minor: number;
    commission_minor: number;
    free_threshold_minor: number | null;
    free_remaining_minor: number | null;
    monthly_cap_minor: number | null;
    cap_remaining_minor: number | null;
    cap_reached: boolean;
    threshold_reached: boolean;
    rate_bps: number | null;
    raised_rate_bps: number | null;
    integration_codes: string[];
    recomputed_at: string | null;
};

type CommissionItem = {
    id: number;
    booking_code: string | null;
    booking_starts_at: string | null;
    realized_at: string | null;
    amount_minor: number;
    rate_bps: number;
    state: 'billable' | 'removed';
    currency: string;
};

type Invoice = {
    id: number;
    period: string;
    commission_net_minor: number;
    vat_minor: number;
    vat_bps: number;
    total_gross_minor: number;
    currency: string;
    status: string;
    issued_at: string | null;
    due_at: string | null;
    paid_at: string | null;
    has_pdf: boolean;
};

type IndexProps = {
    overview: Overview;
    items: Paginator<CommissionItem>;
    invoices: Invoice[];
    periods: string[];
    filters: { period: string };
    suspended: boolean;
};

export default function BillingIndex({
    overview,
    items,
    invoices,
    periods,
    filters,
    suspended,
}: IndexProps) {
    const t = useTranslations();
    const currency = overview.currency;
    const hasOverdue = invoices.some((invoice) => invoice.status === 'overdue');

    function selectPeriod(period: string) {
        router.get(
            '/billing',
            { period },
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.billing.title') }]}>
            <Head title={t('admin.billing.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.billing.title')}
                    description={t('admin.billing.subtitle')}
                />

                {suspended ? (
                    <div className="rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm">
                        <p className="font-medium text-destructive">
                            {t('admin.billing.suspended_notice.title')}
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            {t('admin.billing.suspended_notice.body')}
                        </p>
                    </div>
                ) : hasOverdue ? (
                    <p className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                        {t('admin.billing.invoice.overdue_notice')}
                    </p>
                ) : null}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <label className="flex items-center gap-2 text-sm">
                        <span className="font-medium">
                            {t('admin.billing.period_label')}
                        </span>
                        <select
                            value={filters.period}
                            onChange={(e) => selectPeriod(e.target.value)}
                            className="rounded-md border border-input bg-transparent px-3 py-1.5 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            {periods.map((period) => (
                                <option key={period} value={period}>
                                    {period}
                                </option>
                            ))}
                        </select>
                        <Badge
                            className={billingStatusBadgeClass(overview.status)}
                            variant="secondary"
                        >
                            {t(`billing_period_status.${overview.status}`)}
                        </Badge>
                    </label>

                    {overview.recomputed_at ? (
                        <span className="text-xs text-muted-foreground">
                            {t('admin.billing.commission.recomputed_at', {
                                time: formatDateTime(overview.recomputed_at),
                            })}
                        </span>
                    ) : null}
                </div>

                {overview.configured ? null : (
                    <p className="rounded-xl border border-dashed border-border bg-muted/20 px-4 py-3 text-sm text-muted-foreground">
                        {t('admin.billing.not_configured')}
                    </p>
                )}

                <Summary overview={overview} />

                {overview.configured ? <Scale overview={overview} /> : null}

                <RateNotice overview={overview} />

                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                {t('admin.billing.commission.items_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('admin.billing.commission.items_subtitle')}
                            </p>
                        </div>
                        {items.total > 0 ? (
                            <Button variant="outline" asChild>
                                <a
                                    href={`/billing/export?period=${filters.period}`}
                                >
                                    <DownloadIcon className="size-4" />
                                    {t('admin.billing.commission.export')}
                                </a>
                            </Button>
                        ) : null}
                    </div>

                    {items.total === 0 ? (
                        <EmptyState
                            icon={CoinsIcon}
                            title={t('admin.billing.commission.empty')}
                        />
                    ) : (
                        <ItemsTable items={items} currency={currency} />
                    )}
                </section>

                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t('admin.billing.late_cancel_notice', {
                        state: t('commission_item_state.removed'),
                    })}
                </p>

                <Invoices invoices={invoices} />
            </div>
        </AdminLayout>
    );
}

function Summary({ overview }: { overview: Overview }) {
    const t = useTranslations();
    const currency = overview.currency;

    const freeRemaining =
        overview.free_remaining_minor === null
            ? '—'
            : formatMoney(overview.free_remaining_minor, currency);

    const capRemaining =
        overview.cap_remaining_minor === null
            ? '—'
            : formatMoney(overview.cap_remaining_minor, currency);

    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                icon={TrendingUpIcon}
                label={t('admin.billing.commission.turnover')}
                value={formatMoney(overview.turnover_minor, currency)}
                hint={t('admin.billing.commission.turnover_hint')}
            />
            <StatCard
                icon={GiftIcon}
                label={t('admin.billing.commission.free_remaining')}
                value={freeRemaining}
                hint={
                    overview.free_threshold_minor === null
                        ? undefined
                        : overview.free_remaining_minor === 0
                          ? t('admin.billing.commission.free_used_up')
                          : t('admin.billing.commission.free_remaining_hint', {
                                threshold: formatMoney(
                                    overview.free_threshold_minor,
                                    currency,
                                ),
                            })
                }
            />
            <StatCard
                icon={CoinsIcon}
                label={t('admin.billing.commission.accrued')}
                value={formatMoney(overview.commission_minor, currency)}
                hint={
                    // Deliberately no rate here: a mid-month rate change (§2.4)
                    // leaves the accrued total built from per-item snapshots, so
                    // naming one rate would misexplain a correct number. The
                    // per-row rate is in the ledger, today's rate in RateNotice.
                    overview.rate_bps === null
                        ? undefined
                        : t('admin.billing.commission.accrued_hint', {
                              base: formatMoney(
                                  overview.billable_base_minor,
                                  currency,
                              ),
                          })
                }
            />
            <StatCard
                icon={GaugeIcon}
                label={t('admin.billing.commission.cap_remaining')}
                value={capRemaining}
                hint={
                    overview.monthly_cap_minor === null
                        ? t('admin.billing.commission.no_cap')
                        : t('admin.billing.commission.cap_remaining_hint', {
                              cap: formatMoney(
                                  overview.monthly_cap_minor,
                                  currency,
                              ),
                          })
                }
            />
        </div>
    );
}

/**
 * Where the tenant stands on the threshold → cap scale (docs/10 §9). Two honest
 * bars rather than one: the free allowance is consumed by *turnover*, while the
 * cap limits the *commission* — squeezing both onto one axis would misrepresent
 * the pricing model.
 */
function Scale({ overview }: { overview: Overview }) {
    const t = useTranslations();
    const currency = overview.currency;
    const threshold = overview.free_threshold_minor;
    const cap = overview.monthly_cap_minor;

    return (
        <section className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5">
            <h2 className="text-sm font-medium text-muted-foreground">
                {t('admin.billing.commission.scale_title')}
            </h2>

            {threshold === null ? null : (
                <Meter
                    label={t('admin.billing.commission.scale_free')}
                    caption={t('admin.billing.commission.scale_threshold', {
                        threshold: formatMoney(threshold, currency),
                    })}
                    value={overview.turnover_minor}
                    max={threshold}
                    tone={overview.threshold_reached ? 'amber' : 'primary'}
                />
            )}

            {cap === null ? null : (
                <Meter
                    label={t('admin.billing.commission.accrued')}
                    caption={t('admin.billing.commission.scale_cap', {
                        cap: formatMoney(cap, currency),
                    })}
                    value={overview.commission_minor}
                    max={cap}
                    tone={overview.cap_reached ? 'green' : 'primary'}
                />
            )}

            {overview.threshold_reached ? (
                <p className="text-sm text-amber-400">
                    {t('admin.billing.threshold_reached')}
                </p>
            ) : null}
            {overview.cap_reached ? (
                <p className="text-sm text-green-400">
                    {t('admin.billing.cap_reached')}
                </p>
            ) : null}
        </section>
    );
}

function Meter({
    label,
    caption,
    value,
    max,
    tone,
}: {
    label: string;
    caption: string;
    value: number;
    max: number;
    tone: 'primary' | 'amber' | 'green';
}) {
    // max is a money/commission ceiling and is never 0 in practice, but a 0 would
    // otherwise render NaN% width.
    const percent = max <= 0 ? 100 : Math.min(100, Math.round((value / max) * 100));
    const fill = {
        primary: 'bg-primary',
        amber: 'bg-amber-500',
        green: 'bg-green-500',
    }[tone];

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="font-medium">{label}</span>
                <span className="text-xs text-muted-foreground">{caption}</span>
            </div>
            <div
                role="progressbar"
                aria-label={label}
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
                className="h-2 overflow-hidden rounded-full bg-muted"
            >
                <div
                    className={`h-full rounded-full transition-all ${fill}`}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

/** Why the rate is 1% or 1,5% (docs/10 §2.4, §9). */
function RateNotice({ overview }: { overview: Overview }) {
    const t = useTranslations();

    if (overview.rate_bps === null) {
        return null;
    }

    const rate = formatRate(overview.rate_bps);
    const raisedByIntegration = overview.integration_codes.length > 0;

    return (
        <p className="max-w-3xl text-sm text-muted-foreground">
            {raisedByIntegration
                ? t('admin.billing.rate_notice_integration', {
                      rate,
                      integrations: overview.integration_codes
                          .map((code) => t(`features.${code}`))
                          .join(', '),
                  })
                : t('admin.billing.rate_notice_base', {
                      rate,
                      raised: formatRate(overview.raised_rate_bps ?? 0),
                  })}
        </p>
    );
}

function ItemsTable({
    items,
    currency,
}: {
    items: Paginator<CommissionItem>;
    currency: string;
}) {
    const t = useTranslations();

    return (
        <>
            <div className="overflow-x-auto rounded-2xl border border-border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th className="px-4 py-3 font-medium">
                                {t('admin.billing.table.booking')}
                            </th>
                            <th className="px-4 py-3 font-medium">
                                {t('admin.billing.table.booking_date')}
                            </th>
                            <th className="px-4 py-3 font-medium">
                                {t('admin.billing.table.realized_at')}
                            </th>
                            <th className="px-4 py-3 text-right font-medium">
                                {t('admin.billing.table.amount')}
                            </th>
                            <th className="px-4 py-3 text-right font-medium">
                                {t('admin.billing.table.rate')}
                            </th>
                            <th className="px-4 py-3 font-medium">
                                {t('admin.billing.table.state')}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {items.data.map((item) => {
                            const removed = item.state === 'removed';

                            return (
                                <tr
                                    key={item.id}
                                    className={removed ? 'opacity-60' : ''}
                                >
                                    <td className="px-4 py-3 font-mono text-xs">
                                        {item.booking_code ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {formatDate(item.booking_starts_at)}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {formatDateTime(item.realized_at)}
                                    </td>
                                    <td
                                        className={`px-4 py-3 text-right tabular-nums ${
                                            removed ? 'line-through' : ''
                                        }`}
                                    >
                                        {formatMoney(
                                            item.amount_minor,
                                            item.currency || currency,
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatRate(item.rate_bps)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge
                                            variant={
                                                removed
                                                    ? 'secondary'
                                                    : 'default'
                                            }
                                        >
                                            {t(
                                                `commission_item_state.${item.state}`,
                                            )}
                                        </Badge>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {items.last_page > 1 ? (
                <div className="flex flex-wrap gap-1">
                    {items.links.map((link, i) => (
                        <button
                            key={i}
                            disabled={!link.url}
                            onClick={() =>
                                link.url &&
                                router.get(
                                    link.url,
                                    {},
                                    { preserveState: true, preserveScroll: true },
                                )
                            }
                            className={`rounded-md px-3 py-1.5 text-sm ${
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-accent disabled:opacity-40'
                            }`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            ) : null}
        </>
    );
}

function Invoices({ invoices }: { invoices: Invoice[] }) {
    const t = useTranslations();

    return (
        <section className="flex flex-col gap-3">
            <div>
                <h2 className="text-lg font-semibold">
                    {t('admin.billing.invoice.title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('admin.billing.invoice.subtitle')}
                </p>
            </div>

            {invoices.length === 0 ? (
                <EmptyState
                    icon={CoinsIcon}
                    title={t('admin.billing.invoice.empty')}
                />
            ) : (
                <div className="overflow-x-auto rounded-2xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    {t('admin.billing.invoice.period')}
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    {t('admin.billing.invoice.net')}
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    {t('admin.billing.invoice.vat', {
                                        rate: formatRate(
                                            invoices[0]?.vat_bps ?? 0,
                                        ),
                                    })}
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    {t('admin.billing.invoice.gross')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('admin.billing.invoice.due_at')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('admin.billing.invoice.status')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('admin.billing.invoice.pdf')}
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {invoices.map((invoice) => (
                                <tr key={invoice.id}>
                                    <td className="px-4 py-3 font-medium">
                                        {invoice.period}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatMoney(
                                            invoice.commission_net_minor,
                                            invoice.currency,
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                        {formatMoney(
                                            invoice.vat_minor,
                                            invoice.currency,
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">
                                        {formatMoney(
                                            invoice.total_gross_minor,
                                            invoice.currency,
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {invoice.paid_at
                                            ? formatDate(invoice.paid_at)
                                            : formatDate(invoice.due_at)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge
                                            className={billingStatusBadgeClass(
                                                invoice.status,
                                            )}
                                            variant="secondary"
                                        >
                                            {t(
                                                `commission_invoice_status.${invoice.status}`,
                                            )}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        {invoice.has_pdf ? (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <a
                                                    href={`/billing/invoices/${invoice.id}/pdf`}
                                                >
                                                    <DownloadIcon className="size-4" />
                                                    {t(
                                                        'admin.billing.invoice.pdf',
                                                    )}
                                                </a>
                                            </Button>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
