import { Head, useForm, usePage } from '@inertiajs/react';
import { CalendarPlusIcon, PencilIcon, XCircleIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import PublicLayout from '@/Layouts/PublicLayout';
import ConfirmDialog from '@/components/admin/ConfirmDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import type { MyBooking } from '@/types';

type BookingsProps = {
    upcoming: MyBooking[];
    past: MyBooking[];
};

export default function Bookings({ upcoming, past }: BookingsProps) {
    const t = useTranslations();
    const page = usePage();
    const [confirming, setConfirming] = useState<MyBooking | null>(null);
    const form = useForm({});

    // A cancellation past the deadline comes back as a `cancel` validation error
    // on the shared errors bag (CancelBooking online guard), not a field error.
    const cancelError = (page.props.errors as Record<string, string> | undefined)
        ?.cancel;

    const cancel = (booking: MyBooking) => {
        form.post(`/my/bookings/${booking.id}/cancel`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('tenant.my.bookings.canceled')),
        });
    };

    return (
        <PublicLayout>
            <Head title={t('tenant.my.bookings.title')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my.bookings.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('tenant.my.bookings.subtitle')}
                    </p>
                </header>

                {cancelError ? (
                    <p
                        role="alert"
                        className="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    >
                        {cancelError}
                    </p>
                ) : null}

                <BookingSection
                    title={t('tenant.my.bookings.upcoming')}
                    bookings={upcoming}
                    emptyLabel={t('tenant.my.bookings.empty')}
                    onCancel={setConfirming}
                    canceling={form.processing}
                />

                <BookingSection
                    title={t('tenant.my.bookings.past')}
                    bookings={past}
                    emptyLabel={t('tenant.my.bookings.empty_past')}
                    onCancel={setConfirming}
                    canceling={form.processing}
                    muted
                />
            </div>

            <ConfirmDialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={t('tenant.my.bookings.cancel_confirm_title')}
                description={t('tenant.my.bookings.cancel_confirm_body')}
                confirmLabel={t('tenant.my.bookings.cancel_submit')}
                cancelLabel={t('tenant.my.bookings.cancel_dismiss')}
                destructive
                onConfirm={() => {
                    if (confirming) {
                        cancel(confirming);
                    }
                }}
            />
        </PublicLayout>
    );
}

type SectionProps = {
    title: string;
    bookings: MyBooking[];
    emptyLabel: string;
    onCancel: (booking: MyBooking) => void;
    canceling: boolean;
    muted?: boolean;
};

function BookingSection({
    title,
    bookings,
    emptyLabel,
    onCancel,
    canceling,
    muted = false,
}: SectionProps) {
    const t = useTranslations();

    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-sm font-semibold tracking-tight text-muted-foreground uppercase">
                {title}
            </h2>

            {bookings.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                    {emptyLabel}
                </p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {bookings.map((booking) => (
                        <li
                            key={booking.id}
                            className={`flex flex-col gap-3 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between ${
                                muted ? 'opacity-80' : ''
                            }`}
                        >
                            <div className="flex flex-col gap-1">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">
                                        {booking.service ?? '—'}
                                    </span>
                                    <Badge variant="outline">
                                        {t(`booking_status.${booking.status}`)}
                                    </Badge>
                                </div>
                                <span className="text-sm text-muted-foreground">
                                    {booking.starts_local ??
                                        t('tenant.my.bookings.no_time')}
                                    {booking.staff
                                        ? ` · ${booking.staff}`
                                        : ''}
                                </span>
                                <span className="font-mono text-xs tracking-widest text-muted-foreground">
                                    {t('tenant.my.bookings.code_label')}:{' '}
                                    {booking.code}
                                </span>
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                >
                                    <a href={`/my/bookings/${booking.id}/ics`}>
                                        <CalendarPlusIcon className="size-4" />
                                        {t('tenant.my.bookings.ics')}
                                    </a>
                                </Button>
                                {booking.can_reschedule ? (
                                    <Button asChild variant="outline" size="sm">
                                        <a
                                            href={`/my/bookings/${booking.id}/reschedule`}
                                        >
                                            <PencilIcon className="size-4" />
                                            {t('tenant.my.bookings.reschedule')}
                                        </a>
                                    </Button>
                                ) : null}
                                {booking.can_cancel ? (
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        disabled={canceling}
                                        onClick={() => onCancel(booking)}
                                    >
                                        <XCircleIcon className="size-4" />
                                        {t('tenant.my.bookings.cancel')}
                                    </Button>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
