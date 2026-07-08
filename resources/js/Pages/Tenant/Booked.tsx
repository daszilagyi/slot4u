import { Head, Link } from '@inertiajs/react';
import { CalendarPlusIcon, CheckCircle2Icon } from 'lucide-react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import type { BookedBooking } from '@/types';

type BookedProps = {
    booking: BookedBooking;
};

const KNOWN_STATUSES = ['confirmed', 'requested', 'pending_payment'];

export default function Booked({ booking }: BookedProps) {
    const t = useTranslations();

    const statusKey = KNOWN_STATUSES.includes(booking.status)
        ? booking.status
        : 'default';

    const rows = [
        { label: t('tenant.booked.service'), value: booking.service ?? '—' },
        { label: t('tenant.booked.staff'), value: booking.staff ?? '—' },
        {
            label: t('tenant.booked.when'),
            value: booking.starts_local ?? '—',
        },
    ];

    return (
        <PublicLayout>
            <Head title={t('tenant.booked.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 py-16 sm:px-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <CheckCircle2Icon className="size-12 text-primary" />
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.booked.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t(`tenant.booked.status.${statusKey}`)}
                    </p>
                </div>

                <div className="flex flex-col items-center gap-1 rounded-xl border border-border bg-card px-4 py-6 text-center">
                    <span className="text-xs text-muted-foreground uppercase">
                        {t('tenant.booked.code_label')}
                    </span>
                    <Badge
                        variant="outline"
                        className="font-mono text-base tracking-widest"
                    >
                        {booking.code}
                    </Badge>
                </div>

                <dl className="grid gap-px overflow-hidden rounded-xl border border-border bg-border">
                    {rows.map((row) => (
                        <div
                            key={row.label}
                            className="flex items-center justify-between bg-card px-4 py-3 text-sm"
                        >
                            <dt className="text-muted-foreground">
                                {row.label}
                            </dt>
                            <dd className="font-medium">{row.value}</dd>
                        </div>
                    ))}
                </dl>

                <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
                    <Button asChild variant="outline">
                        <a href={`/booked/${booking.code}/ics`}>
                            <CalendarPlusIcon className="size-4" />
                            {t('tenant.booked.ics')}
                        </a>
                    </Button>
                    <Button asChild>
                        <Link href="/">{t('tenant.booked.back')}</Link>
                    </Button>
                </div>
            </div>
        </PublicLayout>
    );
}
