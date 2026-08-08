import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarCheckIcon,
    CalendarDaysIcon,
    ClockIcon,
    HourglassIcon,
    TrendingUpIcon,
    UsersIcon,
} from 'lucide-react';
import { type ReactNode, useCallback } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/components/admin/EmptyState';
import PageHeader from '@/components/admin/PageHeader';
import StatCard from '@/components/admin/StatCard';
import { Badge } from '@/components/ui/badge';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import { useLiveBookings } from '@/lib/useLiveBookings';
import { cn } from '@/lib/utils';
import type { DashboardBooking, TenantDashboard } from '@/types';

type DashboardProps = {
    dashboard: TenantDashboard;
};

/**
 * Tenant admin bento dashboard (SLO-43, docs/05 M7). Every figure arrives already
 * resolved to the tenant's timezone and scoped to the actor (BuildTenantDashboard),
 * so this file only lays them out. A `null` block means "no permission" and is
 * dropped rather than rendered as a zero the actor may not know.
 *
 * "Live" is a partial reload, not an optimistic local mutation: when a booking
 * lands over Reverb the page re-pulls just the `dashboard` prop, so every number
 * comes from the same server-side scoping as the first render instead of a
 * client-side guess about which tiles the new booking belongs to.
 */
export default function AdminDashboard({ dashboard }: DashboardProps) {
    const t = useTranslations();
    const { auth, tenant } = usePage().props;

    const refresh = useCallback(() => {
        router.reload({ only: ['dashboard'] });
    }, []);

    // Live booking feed: a toast + chime the moment a booking comes in (SLO-118),
    // and a refresh of the grid behind it (SLO-43).
    useLiveBookings(tenant?.id, refresh);

    return (
        <AdminLayout>
            <Head title={t('admin.dashboard.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={
                        auth.user
                            ? t('admin.dashboard.welcome', {
                                  name: auth.user.name,
                              })
                            : t('admin.dashboard.title')
                    }
                    description={t('admin.dashboard.subtitle')}
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {dashboard.bookings_today !== null ? (
                        <StatCard
                            label={t('admin.dashboard.stat.today_bookings')}
                            value={String(dashboard.bookings_today)}
                            hint={t(
                                'admin.dashboard.stat.today_bookings_hint',
                                { count: String(dashboard.confirmed_today ?? 0) },
                            )}
                            icon={CalendarCheckIcon}
                        />
                    ) : null}
                    {dashboard.revenue_today_minor !== null ? (
                        <StatCard
                            label={t('admin.dashboard.stat.revenue')}
                            value={formatMoney(
                                dashboard.revenue_today_minor,
                                dashboard.currency,
                            )}
                            hint={t('admin.dashboard.stat.revenue_hint')}
                            icon={TrendingUpIcon}
                        />
                    ) : null}
                    {dashboard.pending_approval !== null ? (
                        <StatCard
                            label={t('admin.dashboard.stat.pending_approval')}
                            value={String(dashboard.pending_approval)}
                            hint={t(
                                'admin.dashboard.stat.pending_approval_hint',
                                { count: String(dashboard.pending_payment ?? 0) },
                            )}
                            icon={HourglassIcon}
                            // The tile counts `requested` bookings, so it leads to
                            // exactly that slice of the list (SLO-144).
                            href="/bookings?status=requested"
                        />
                    ) : null}
                    {dashboard.customers_total !== null ? (
                        <StatCard
                            label={t('admin.dashboard.stat.customers')}
                            value={String(dashboard.customers_total)}
                            hint={t('admin.dashboard.stat.customers_hint', {
                                count: String(
                                    dashboard.customers_new_this_month ?? 0,
                                ),
                            })}
                            icon={UsersIcon}
                        />
                    ) : null}
                </div>

                {dashboard.agenda === null ? (
                    <EmptyState
                        icon={CalendarDaysIcon}
                        title={t('admin.dashboard.no_permission')}
                    />
                ) : (
                    <div className="grid gap-4 xl:grid-cols-3">
                        <Agenda
                            bookings={dashboard.agenda}
                            total={dashboard.bookings_today ?? 0}
                            today={dashboard.date}
                            timezone={dashboard.timezone}
                            className="xl:col-span-2"
                        />
                        <MonthCalendar
                            days={dashboard.calendar ?? []}
                            today={dashboard.date}
                        />
                        <RecentBookings
                            bookings={dashboard.recent ?? []}
                            className="xl:col-span-3"
                        />
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

/** Card shell shared by the bento panels. */
function Panel({
    title,
    action,
    className,
    children,
}: {
    title: string;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            className={cn(
                'flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-base font-medium">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

function StatusBadge({ status }: { status: DashboardBooking['status'] }) {
    const t = useTranslations();

    return (
        <Badge variant="secondary" className="shrink-0">
            {t(`booking_status.${status}`)}
        </Badge>
    );
}

/**
 * Today's timeline. Times render in the *tenant's* zone, not the browser's — an
 * admin travelling abroad must still read their own opening hours.
 */
function Agenda({
    bookings,
    total,
    today,
    timezone,
    className,
}: {
    bookings: DashboardBooking[];
    /** Every booking of the day, which may exceed what the panel lists. */
    total: number;
    today: string;
    timezone: string;
    className?: string;
}) {
    const t = useTranslations();
    const hidden = Math.max(total - bookings.length, 0);

    return (
        <Panel
            title={t('admin.dashboard.today_title')}
            action={
                <span className="text-xs text-muted-foreground">
                    {t('admin.dashboard.timezone_note', { tz: timezone })}
                </span>
            }
            className={className}
        >
            {bookings.length === 0 ? (
                <EmptyState
                    icon={CalendarCheckIcon}
                    title={t('admin.dashboard.today_empty')}
                />
            ) : (
                <ul className="flex flex-col divide-y divide-border">
                    {bookings.map((booking) => (
                        <li
                            key={booking.id}
                            className="flex items-center gap-4 py-3 first:pt-0 last:pb-0"
                        >
                            <span className="w-16 shrink-0 text-sm font-medium tabular-nums">
                                {booking.starts_at ? (
                                    new Date(
                                        booking.starts_at,
                                    ).toLocaleTimeString('hu-HU', {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                        timeZone: timezone,
                                    })
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        {t('admin.dashboard.today_no_time')}
                                    </span>
                                )}
                            </span>
                            <div className="flex min-w-0 flex-1 flex-col">
                                <Link
                                    href={`/bookings/${booking.id}`}
                                    className="truncate text-sm font-medium hover:underline"
                                >
                                    {booking.service ?? booking.code}
                                </Link>
                                <span className="truncate text-xs text-muted-foreground">
                                    {[booking.customer, booking.staff]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </div>
                            <StatusBadge status={booking.status} />
                        </li>
                    ))}
                    {hidden > 0 ? (
                        <li className="pt-3">
                            <Link
                                href={`/bookings?from=${today}&to=${today}`}
                                className="text-sm text-primary hover:underline"
                            >
                                {t('admin.dashboard.today_more', {
                                    count: String(hidden),
                                })}
                            </Link>
                        </li>
                    ) : null}
                </ul>
            )}
        </Panel>
    );
}

function RecentBookings({
    bookings,
    className,
}: {
    bookings: DashboardBooking[];
    className?: string;
}) {
    const t = useTranslations();

    return (
        <Panel
            title={t('admin.dashboard.recent_title')}
            action={
                <Link
                    href="/bookings"
                    className="text-sm text-primary hover:underline"
                >
                    {t('admin.dashboard.recent_link')}
                </Link>
            }
            className={className}
        >
            {bookings.length === 0 ? (
                <EmptyState
                    icon={ClockIcon}
                    title={t('admin.dashboard.recent_empty')}
                />
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {bookings.map((booking) => (
                        <li key={booking.id}>
                            <Link
                                href={`/bookings/${booking.id}`}
                                className="flex h-full flex-col gap-2 rounded-xl border border-border p-4 transition-colors hover:border-primary/40"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <span className="truncate text-sm font-medium">
                                        {booking.service ?? booking.code}
                                    </span>
                                    <StatusBadge status={booking.status} />
                                </div>
                                <span className="truncate text-xs text-muted-foreground">
                                    {booking.customer ?? '—'}
                                    {booking.is_guest
                                        ? ` · ${t('admin.dashboard.guest_badge')}`
                                        : ''}
                                </span>
                                <span className="mt-auto text-sm font-semibold tabular-nums">
                                    {formatMoney(
                                        booking.price_minor,
                                        booking.currency,
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/**
 * Month heat calendar. Weeks start on Monday (Hungarian convention). The dates are
 * the tenant-local ones the server already bucketed, so no client-side timezone
 * arithmetic can shift a booking into the neighbouring day — the only thing derived
 * here is which weekday column the first of the month lands in, and that is read
 * off the date string as UTC so the browser's own zone cannot skew it either.
 * Clicking a day opens the booking list filtered to it.
 */
function MonthCalendar({
    days,
    today,
}: {
    days: { date: string; count: number }[];
    today: string;
}) {
    const t = useTranslations();

    if (days.length === 0) {
        return null;
    }

    // getUTCDay(): 0 = Sunday … 6 = Saturday → 1 = Monday … 7 = Sunday.
    const firstWeekday =
        ((new Date(`${days[0].date}T00:00:00Z`).getUTCDay() + 6) % 7) + 1;
    const busiest = Math.max(...days.map((day) => day.count));

    return (
        <Panel title={t('admin.dashboard.calendar_title')}>
            <div className="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground">
                {[1, 2, 3, 4, 5, 6, 7].map((weekday) => (
                    <span key={weekday}>
                        {t(`admin.dashboard.weekday.${weekday}`)}
                    </span>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-1">
                {Array.from({ length: firstWeekday - 1 }, (_, index) => (
                    <span key={`blank-${index}`} />
                ))}
                {days.map((day) => {
                    const isToday = day.date === today;
                    // Three steps of tint so a busy day reads at a glance, no legend.
                    const intensity =
                        day.count === 0 || busiest === 0
                            ? 0
                            : Math.ceil((day.count / busiest) * 3);

                    return (
                        <Link
                            key={day.date}
                            href={`/bookings?from=${day.date}&to=${day.date}`}
                            title={t('admin.dashboard.calendar_bookings', {
                                count: String(day.count),
                            })}
                            className={cn(
                                'flex aspect-square flex-col items-center justify-center rounded-lg border border-transparent text-xs transition-colors hover:border-primary/40',
                                intensity === 0 && 'bg-muted/40',
                                intensity === 1 && 'bg-primary/15',
                                intensity === 2 && 'bg-primary/30',
                                intensity === 3 && 'bg-primary/50',
                                isToday && 'border-primary font-semibold',
                            )}
                        >
                            <span className="tabular-nums">
                                {Number(day.date.slice(-2))}
                            </span>
                            {day.count > 0 ? (
                                <span className="text-[10px] tabular-nums opacity-70">
                                    {day.count}
                                </span>
                            ) : null}
                        </Link>
                    );
                })}
            </div>
            <p className="text-xs text-muted-foreground">
                {t('admin.dashboard.calendar_hint')}
            </p>
        </Panel>
    );
}
