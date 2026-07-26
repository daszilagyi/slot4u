import { Head, Link } from '@inertiajs/react';
import {
    CalendarPlusIcon,
    CheckCircle2Icon,
    CreditCardIcon,
    XCircleIcon,
} from 'lucide-react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { BookedBooking } from '@/types';

type BookedProps = {
    booking: BookedBooking;
};

// The /booked/{code} link is permanent, so an admin may later cancel, reject or
// mark the booking as a no-show — reflect that instead of "recorded" (SLO-93).
const NEGATIVE_STATUSES = ['canceled', 'rejected', 'no_show'] as const;

const KNOWN_STATUSES = [
    'confirmed',
    'requested',
    'pending_payment',
    // A digital no_time_slot order is completed on creation (SLO-101, docs/04 §1).
    'completed',
    ...NEGATIVE_STATUSES,
];

export default function Booked({ booking }: BookedProps) {
    const t = useTranslations();

    const statusKey = KNOWN_STATUSES.includes(booking.status)
        ? booking.status
        : 'default';

    // A canceled/rejected/no-show booking is no longer a confirmation, so drop the
    // green check to avoid a message/icon mismatch on the permanent link (SLO-93).
    const isNegative = (NEGATIVE_STATUSES as readonly string[]).includes(
        booking.status,
    );

    // A no_time_slot order has no appointment: no "when" row, and nothing to add
    // to a calendar (the .ics route 404s for it too).
    const isScheduled = booking.starts_local !== null;

    const rows = [
        { label: t('tenant.booked.service'), value: booking.service ?? '—' },
        { label: t('tenant.booked.staff'), value: booking.staff ?? '—' },
        ...(booking.starts_local !== null
            ? [{ label: t('tenant.booked.when'), value: booking.starts_local }]
            : []),
    ];

    return (
        <PublicLayout>
            <Head title={t('tenant.booked.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 py-16 sm:px-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    {isNegative ? (
                        <XCircleIcon className="size-12 text-destructive" />
                    ) : (
                        <CheckCircle2Icon className="size-12 text-primary" />
                    )}
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.booked.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t(`tenant.booked.status.${statusKey}`)}
                    </p>
                </div>

                {booking.payable ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-primary/40 bg-card px-4 py-6 text-center">
                        <p className="text-sm text-muted-foreground">
                            {booking.payment_deadline_local
                                ? t('tenant.booked.pay_deadline', {
                                      deadline: booking.payment_deadline_local,
                                  })
                                : t('tenant.booked.pay_intro')}
                        </p>
                        <Button asChild>
                            <a href={`/pay/${booking.code}`}>
                                <CreditCardIcon className="size-4" />
                                {t('tenant.booked.pay', {
                                    amount: formatMoney(
                                        booking.price_minor,
                                        booking.currency,
                                    ),
                                })}
                            </a>
                        </Button>
                    </div>
                ) : null}

                {booking.content_url ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-primary/40 bg-card px-4 py-6 text-center">
                        <p className="text-sm text-muted-foreground">
                            {t('tenant.booked.content_intro')}
                        </p>
                        <Button asChild>
                            <a
                                href={booking.content_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {t('tenant.booked.content_link')}
                            </a>
                        </Button>
                    </div>
                ) : null}

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
                    {isScheduled ? (
                        <Button asChild variant="outline">
                            <a href={`/booked/${booking.code}/ics`}>
                                <CalendarPlusIcon className="size-4" />
                                {t('tenant.booked.ics')}
                            </a>
                        </Button>
                    ) : null}
                    <Button asChild>
                        <Link href="/">{t('tenant.booked.back')}</Link>
                    </Button>
                </div>
            </div>
        </PublicLayout>
    );
}
