import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    CalendarCheckIcon,
    CalendarIcon,
    MailIcon,
    PhoneIcon,
    WalletIcon,
} from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import StatCard from '@/components/admin/StatCard';
import { Badge } from '@/components/ui/badge';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { CustomerCard } from '@/types';

type ShowProps = {
    customer: CustomerCard;
    can: { edit: boolean };
};

export default function CustomerShow({ customer }: ShowProps) {
    const t = useTranslations();

    return (
        <AdminLayout
            breadcrumbs={[
                { label: t('admin.customers.title'), href: '/customers' },
                { label: customer.name },
            ]}
        >
            <Head title={customer.name} />

            <div className="flex flex-col gap-6">
                <Link
                    href="/customers"
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeftIcon className="size-4" />
                    {t('admin.customers.card.back')}
                </Link>

                <PageHeader
                    title={customer.name}
                    description=""
                    actions={
                        <div className="flex flex-col items-end gap-1 text-sm text-muted-foreground">
                            <span className="inline-flex items-center gap-1.5">
                                <MailIcon className="size-3.5" />
                                {customer.email}
                            </span>
                            {customer.phone ? (
                                <span className="inline-flex items-center gap-1.5">
                                    <PhoneIcon className="size-3.5" />
                                    {customer.phone}
                                </span>
                            ) : null}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        icon={CalendarIcon}
                        label={t('admin.customers.card.bookings')}
                        value={String(customer.bookings_count)}
                    />
                    <StatCard
                        icon={CalendarCheckIcon}
                        label={t('admin.customers.card.completed')}
                        value={String(customer.completed_count)}
                    />
                    <StatCard
                        icon={WalletIcon}
                        label={t('admin.customers.card.total_spend')}
                        value={formatMoney(
                            customer.total_spend_minor,
                            customer.currency,
                        )}
                    />
                </div>

                <div className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">
                        {t('admin.customers.card.recent')}
                    </h2>

                    {customer.recent_bookings.length === 0 ? (
                        <p className="rounded-xl border border-border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
                            {t('admin.customers.card.no_bookings')}
                        </p>
                    ) : (
                        <div className="overflow-hidden rounded-xl border border-border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">
                                            {t('admin.customers.field.name')}
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            {t('admin.customers.card.staff')}
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            {t('admin.customers.card.status')}
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            {t('admin.customers.card.date')}
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            {t('admin.customers.card.price')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {customer.recent_bookings.map((booking) => (
                                        <tr
                                            key={booking.id}
                                            className="border-t border-border"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="font-medium">
                                                    {booking.service ?? '—'}
                                                </div>
                                                <div className="font-mono text-xs text-muted-foreground">
                                                    {booking.code}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {booking.staff ?? '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline">
                                                    {t(
                                                        `booking_status.${booking.status}`,
                                                    )}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {booking.starts_at
                                                    ? formatDateTime(
                                                          booking.starts_at,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {formatMoney(
                                                    booking.price_minor,
                                                    booking.currency,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
