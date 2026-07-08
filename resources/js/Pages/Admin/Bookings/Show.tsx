import { Head, Link } from '@inertiajs/react';
import { ArrowLeftIcon } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { BookingDetail } from '@/types';

type ShowProps = {
    booking: BookingDetail;
    can: { edit: boolean; cancel: boolean };
};

export default function BookingShow({ booking }: ShowProps) {
    const t = useTranslations();

    const rows: { label: string; value: string }[] = [
        {
            label: t('admin.bookings.field.customer'),
            value: booking.customer ?? '—',
        },
        {
            label: t('admin.bookings.field.service'),
            value: booking.service ?? '—',
        },
        {
            label: t('admin.bookings.field.staff'),
            value: booking.staff ?? '—',
        },
        {
            label: t('admin.bookings.field.room'),
            value: booking.room ?? '—',
        },
        {
            label: t('admin.bookings.field.starts_at'),
            value: formatDateTime(booking.starts_at),
        },
        {
            label: t('admin.bookings.field.party_size'),
            value: String(booking.party_size),
        },
        {
            label: t('admin.bookings.field.price'),
            value: formatMoney(booking.price_minor, booking.currency),
        },
    ];

    if (booking.notes) {
        rows.push({
            label: t('admin.bookings.field.notes'),
            value: booking.notes,
        });
    }

    if (booking.cancel_reason) {
        rows.push({
            label: t('admin.bookings.card.cancel_reason'),
            value: booking.cancel_reason,
        });
    }

    return (
        <AdminLayout
            breadcrumbs={[
                { label: t('admin.bookings.title'), href: '/bookings' },
                { label: booking.code },
            ]}
        >
            <Head title={booking.code} />

            <div className="flex flex-col gap-6">
                <Link
                    href="/bookings"
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeftIcon className="size-4" />
                    {t('admin.bookings.card.back')}
                </Link>

                <PageHeader
                    title={booking.customer ?? booking.code}
                    description={booking.code}
                    actions={
                        <Badge variant="outline">
                            {t(`booking_status.${booking.status}`)}
                        </Badge>
                    }
                />

                <div className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">
                        {t('admin.bookings.card.details')}
                    </h2>
                    <dl className="grid gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-2">
                        {rows.map((row) => (
                            <div
                                key={row.label}
                                className="flex flex-col gap-1 bg-card px-4 py-3"
                            >
                                <dt className="text-xs text-muted-foreground">
                                    {row.label}
                                </dt>
                                <dd className="text-sm">{row.value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>

                <div className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">
                        {t('admin.bookings.card.history')}
                    </h2>

                    {booking.history.length === 0 ? (
                        <p className="rounded-xl border border-border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
                            {t('admin.bookings.card.no_history')}
                        </p>
                    ) : (
                        <ol className="flex flex-col gap-2">
                            {booking.history.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-card px-4 py-2.5 text-sm"
                                >
                                    {entry.from_status ? (
                                        <>
                                            <Badge variant="outline">
                                                {t(
                                                    `booking_status.${entry.from_status}`,
                                                )}
                                            </Badge>
                                            <span className="text-muted-foreground">
                                                →
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">
                                            {t('admin.bookings.card.initial')}
                                        </span>
                                    )}
                                    <Badge variant="outline">
                                        {t(
                                            `booking_status.${entry.to_status}`,
                                        )}
                                    </Badge>
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {entry.actor ? `${entry.actor} · ` : ''}
                                        {formatDateTime(entry.created_at)}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
