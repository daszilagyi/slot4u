import { Head, router } from '@inertiajs/react';
import {
    BanknoteIcon,
    CalendarCheckIcon,
    DownloadIcon,
    TrendingUpIcon,
    UserXIcon,
    UsersIcon,
    XCircleIcon,
} from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import RevenueBars from '@/components/admin/RevenueBars';
import StatCard from '@/components/admin/StatCard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney, formatRate } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ReportResourceRow, TenantReport } from '@/types';

type ReportsProps = {
    report: TenantReport;
    presets: string[];
};

/**
 * Tenant statistics module (SLO-137 / SLO-45, docs/05 M7). Every figure arrives
 * resolved by BuildTenantReport — the tenant's own timezone, its own currency, its
 * own visibility scope — so this file only lays them out and turns integers into
 * bars.
 *
 * The comparison is always against the equally long preceding range, and the page
 * prints that range explicitly next to the deltas: a percentage whose baseline the
 * reader has to guess is worse than no percentage.
 */
export default function Reports({ report, presets }: ReportsProps) {
    const t = useTranslations();
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);

    const apply = (preset: string) => {
        router.get(
            '/reports',
            preset === 'custom' ? { preset, from, to } : { preset },
            { preserveState: true, preserveScroll: true },
        );
    };

    const submitCustom = (event: FormEvent) => {
        event.preventDefault();
        apply('custom');
    };

    const exportUrl = (section: string) => {
        const params = new URLSearchParams({
            preset: report.preset,
            section,
        });

        if (report.preset === 'custom') {
            params.set('from', report.from);
            params.set('to', report.to);
        }

        return `/reports/export?${params.toString()}`;
    };

    const totals = report.totals;
    const previous = report.previous_totals;

    return (
        <AdminLayout>
            <Head title={t('admin.reports.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.reports.title')}
                    description={t('admin.reports.subtitle')}
                />

                <div className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-muted-foreground">
                            {t('admin.reports.range.label')}
                        </span>
                        {presets
                            .filter((preset) => preset !== 'custom')
                            .map((preset) => (
                                <Button
                                    key={preset}
                                    type="button"
                                    size="sm"
                                    variant={
                                        report.preset === preset
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => apply(preset)}
                                >
                                    {t(`admin.reports.range.${preset}`)}
                                </Button>
                            ))}
                    </div>

                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={submitCustom}
                    >
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="report-from">
                                {t('admin.reports.range.from')}
                            </Label>
                            <Input
                                id="report-from"
                                type="date"
                                value={from}
                                onChange={(event) =>
                                    setFrom(event.target.value)
                                }
                                className="w-40"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="report-to">
                                {t('admin.reports.range.to')}
                            </Label>
                            <Input
                                id="report-to"
                                type="date"
                                value={to}
                                onChange={(event) => setTo(event.target.value)}
                                className="w-40"
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            variant={
                                report.preset === 'custom'
                                    ? 'default'
                                    : 'outline'
                            }
                        >
                            {t('admin.reports.range.apply')}
                        </Button>
                    </form>

                    <p className="text-xs text-muted-foreground">
                        {t('admin.reports.range.selected', {
                            from: report.from,
                            to: report.to,
                        })}
                        {' · '}
                        {t('admin.reports.range.compared_to', {
                            from: report.previous_from,
                            to: report.previous_to,
                        })}
                        {' · '}
                        {t('admin.reports.timezone_note', {
                            tz: report.timezone,
                        })}
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <StatCard
                        label={t('admin.reports.stat.revenue')}
                        value={formatMoney(
                            totals.revenue_minor,
                            report.currency,
                        )}
                        hint={deltaHint(
                            t,
                            totals.revenue_minor,
                            previous.revenue_minor,
                        )}
                        icon={BanknoteIcon}
                    />
                    <StatCard
                        label={t('admin.reports.stat.bookings')}
                        value={String(totals.bookings)}
                        hint={`${t('admin.reports.stat.bookings_hint', {
                            count: String(totals.realized),
                        })} · ${deltaHint(t, totals.bookings, previous.bookings)}`}
                        icon={CalendarCheckIcon}
                    />
                    <StatCard
                        label={t('admin.reports.stat.customers')}
                        value={String(totals.customers)}
                        hint={deltaHint(t, totals.customers, previous.customers)}
                        icon={UsersIcon}
                    />
                    <StatCard
                        label={t('admin.reports.stat.average_value')}
                        value={
                            totals.average_value_minor !== null
                                ? formatMoney(
                                      totals.average_value_minor,
                                      report.currency,
                                  )
                                : '—'
                        }
                        hint={t('admin.reports.stat.average_value_hint')}
                        icon={TrendingUpIcon}
                    />
                    <StatCard
                        label={t('admin.reports.stat.no_show_rate')}
                        value={
                            totals.no_show_rate_bps !== null
                                ? formatRate(totals.no_show_rate_bps)
                                : '—'
                        }
                        hint={t('admin.reports.stat.no_show_rate_hint')}
                        icon={UserXIcon}
                    />
                    <StatCard
                        label={t('admin.reports.stat.cancel_rate')}
                        value={
                            totals.cancel_rate_bps !== null
                                ? formatRate(totals.cancel_rate_bps)
                                : '—'
                        }
                        hint={t('admin.reports.stat.cancel_rate_hint')}
                        icon={XCircleIcon}
                    />
                </div>

                <Panel
                    title={t('admin.reports.series.title')}
                    exportHref={exportUrl('daily')}
                >
                    <RevenueBars
                        series={report.series}
                        currency={report.currency}
                    />
                </Panel>

                <div className="grid gap-4 xl:grid-cols-2">
                    <Panel
                        title={t('admin.reports.services.title')}
                        exportHref={exportUrl('services')}
                    >
                        {report.by_service.length === 0 ? (
                            <Empty text={t('admin.reports.services.empty')} />
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-muted-foreground">
                                        <th className="pb-2 font-medium">
                                            {t('admin.reports.services.name')}
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            {t('admin.reports.column.bookings')}
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            {t('admin.reports.column.revenue')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.by_service.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-t border-border"
                                        >
                                            <td className="py-2">
                                                {row.name ??
                                                    t(
                                                        'admin.reports.services.deleted',
                                                    )}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {row.bookings}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {formatMoney(
                                                    row.revenue_minor,
                                                    report.currency,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Panel>

                    <Panel
                        title={t('admin.reports.customers_table.title')}
                        exportHref={exportUrl('customers')}
                    >
                        {report.top_customers.length === 0 ? (
                            <Empty
                                text={t('admin.reports.customers_table.empty')}
                            />
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-muted-foreground">
                                        <th className="pb-2 font-medium">
                                            {t(
                                                'admin.reports.customers_table.name',
                                            )}
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            {t('admin.reports.column.bookings')}
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            {t(
                                                'admin.reports.customers_table.spend',
                                            )}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.top_customers.map((row, index) => (
                                        <tr
                                            key={`${row.name}-${index}`}
                                            className="border-t border-border"
                                        >
                                            <td className="py-2">
                                                <span className="flex items-center gap-2">
                                                    {row.name}
                                                    {row.is_guest ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="px-1.5 text-[10px]"
                                                        >
                                                            {t(
                                                                'admin.reports.guest_badge',
                                                            )}
                                                        </Badge>
                                                    ) : null}
                                                </span>
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {row.bookings}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {formatMoney(
                                                    row.spend_minor,
                                                    report.currency,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Panel>
                </div>

                <ResourcePanel
                    title={t('admin.reports.staff.title')}
                    nameLabel={t('admin.reports.staff.name')}
                    emptyText={t('admin.reports.staff.empty')}
                    rows={report.by_staff}
                    currency={report.currency}
                    exportHref={exportUrl('staff')}
                />

                <ResourcePanel
                    title={t('admin.reports.rooms.title')}
                    nameLabel={t('admin.reports.rooms.name')}
                    emptyText={t('admin.reports.rooms.empty')}
                    rows={report.by_room}
                    currency={report.currency}
                    exportHref={exportUrl('rooms')}
                />
            </div>
        </AdminLayout>
    );
}

/**
 * How this period compares with the preceding one. A period with no baseline says
 * so instead of showing an infinite percentage, and the direction is carried by the
 * wording, not by colour alone.
 */
function deltaHint(
    t: (key: string, replacements?: Record<string, string | number>) => string,
    current: number,
    previous: number,
): string {
    if (previous === 0) {
        return current === 0
            ? t('admin.reports.delta.flat')
            : t('admin.reports.delta.new');
    }

    const change = Math.round(((current - previous) / previous) * 100);

    if (change === 0) {
        return t('admin.reports.delta.flat');
    }

    return change > 0
        ? t('admin.reports.delta.up', { value: String(change) })
        : t('admin.reports.delta.down', { value: String(change) });
}

function Panel({
    title,
    exportHref,
    children,
}: {
    title: string;
    exportHref: string;
    children: ReactNode;
}) {
    const t = useTranslations();

    return (
        <section className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-sm font-semibold">{title}</h2>
                <a
                    href={exportHref}
                    className="inline-flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
                >
                    <DownloadIcon className="size-3.5" />
                    {t('admin.reports.export.button')}
                </a>
            </div>
            {children}
        </section>
    );
}

function Empty({ text }: { text: string }) {
    return (
        <p className="py-6 text-center text-sm text-muted-foreground">{text}</p>
    );
}

/**
 * Staff / room activity. Utilisation is a meter, not a bare number: the reader is
 * comparing rows against each other, and a bar does that at a glance. A resource
 * with no open hours shows "no schedule" rather than 0% — an undefined ratio must
 * not read as idleness.
 */
function ResourcePanel({
    title,
    nameLabel,
    emptyText,
    rows,
    currency,
    exportHref,
}: {
    title: string;
    nameLabel: string;
    emptyText: string;
    rows: ReportResourceRow[];
    currency: string;
    exportHref: string;
}) {
    const t = useTranslations();

    return (
        <Panel title={title} exportHref={exportHref}>
            {rows.length === 0 ? (
                <Empty text={emptyText} />
            ) : (
                <>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-xl text-sm">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    <th className="pb-2 font-medium">
                                        {nameLabel}
                                    </th>
                                    <th className="pb-2 text-right font-medium">
                                        {t('admin.reports.column.bookings')}
                                    </th>
                                    <th className="pb-2 text-right font-medium">
                                        {t('admin.reports.column.revenue')}
                                    </th>
                                    <th className="pb-2 text-right font-medium">
                                        {t('admin.reports.column.booked_hours')}
                                    </th>
                                    <th className="pb-2 text-right font-medium">
                                        {t('admin.reports.column.open_hours')}
                                    </th>
                                    <th className="w-40 pb-2 font-medium">
                                        {t('admin.reports.column.utilization')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border"
                                    >
                                        <td className="py-2">{row.name}</td>
                                        <td className="py-2 text-right tabular-nums">
                                            {row.bookings}
                                        </td>
                                        <td className="py-2 text-right tabular-nums">
                                            {formatMoney(
                                                row.revenue_minor,
                                                currency,
                                            )}
                                        </td>
                                        <td className="py-2 text-right tabular-nums">
                                            {formatHours(t, row.booked_minutes)}
                                        </td>
                                        <td className="py-2 text-right tabular-nums">
                                            {formatHours(
                                                t,
                                                row.scheduled_minutes,
                                            )}
                                        </td>
                                        <td className="py-2 pl-4">
                                            {row.utilization_bps !== null ? (
                                                <span className="flex items-center gap-2">
                                                    <span className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                                        <span
                                                            className={cn(
                                                                'block h-full rounded-full bg-primary',
                                                            )}
                                                            style={{
                                                                width: `${Math.min(100, row.utilization_bps / 100)}%`,
                                                            }}
                                                        />
                                                    </span>
                                                    <span className="w-14 shrink-0 text-right text-xs tabular-nums">
                                                        {formatRate(
                                                            row.utilization_bps,
                                                        )}
                                                    </span>
                                                </span>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    {t(
                                                        'admin.reports.no_schedule',
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('admin.reports.utilization_hint')}
                    </p>
                </>
            )}
        </Panel>
    );
}

/** Minutes as whole-ish hours for the table; the CSV keeps the exact minutes. */
function formatHours(
    t: (key: string, replacements?: Record<string, string | number>) => string,
    minutes: number,
): string {
    return t('admin.reports.hours', {
        count: new Intl.NumberFormat('hu-HU', {
            maximumFractionDigits: 1,
        }).format(minutes / 60),
    });
}
