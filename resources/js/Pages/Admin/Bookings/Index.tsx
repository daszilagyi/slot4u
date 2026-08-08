import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CalendarCheckIcon,
    CalendarClockIcon,
    CheckCircle2Icon,
    ShieldCheckIcon,
    UserXIcon,
    XCircleIcon,
    type LucideIcon,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmDialog from '@/components/admin/ConfirmDialog';
import EmptyState from '@/components/admin/EmptyState';
import FormSheet from '@/components/admin/FormSheet';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    bookingActionKeys,
    bookingActionUrl,
    bookingQuickActions,
    canRescheduleBooking,
    type BookingActionKey,
} from '@/lib/bookingActions';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type {
    BookingAbilities,
    BookingFilterOptions,
    BookingFilters,
    BookingSummary,
    Paginator,
} from '@/types';

type IndexProps = {
    bookings: Paginator<BookingSummary>;
    filters: BookingFilters;
    options: BookingFilterOptions;
    can: BookingAbilities;
};

/** Same order as `bookingQuickActions` returns them. */
const ACTION_ICON: Record<BookingActionKey, LucideIcon> = {
    approve: ShieldCheckIcon,
    complete: CheckCircle2Icon,
    no_show: UserXIcon,
    cancel: XCircleIcon,
};

type PendingConfirm = {
    url: string;
    title: string;
    message: string;
};

export default function BookingsIndex({
    bookings,
    filters,
    options,
    can,
}: IndexProps) {
    const t = useTranslations();

    const [status, setStatus] = useState(filters.status ?? '');
    const [staffId, setStaffId] = useState(
        filters.staff_id ? String(filters.staff_id) : '',
    );
    const [serviceId, setServiceId] = useState(
        filters.service_id ? String(filters.service_id) : '',
    );
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const [confirm, setConfirm] = useState<PendingConfirm | null>(null);
    const [confirmToast, setConfirmToast] = useState('');

    const [rescheduling, setRescheduling] = useState<BookingSummary | null>(
        null,
    );
    const rescheduleForm = useForm({ starts_at: '', ends_at: '' });

    function submitFilters(event: FormEvent) {
        event.preventDefault();
        router.get(
            '/bookings',
            {
                status: status || undefined,
                staff_id: staffId || undefined,
                service_id: serviceId || undefined,
                from: from || undefined,
                to: to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function clearFilters() {
        setStatus('');
        setStaffId('');
        setServiceId('');
        setFrom('');
        setTo('');
        router.get('/bookings', {}, { preserveScroll: true, replace: true });
    }

    function runConfirm() {
        if (!confirm) {
            return;
        }
        const successMessage = confirmToast;
        router.post(
            confirm.url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success(successMessage),
            },
        );
    }

    function askConfirm(pending: PendingConfirm, successMessage: string) {
        setConfirmToast(successMessage);
        setConfirm(pending);
    }

    function openReschedule(booking: BookingSummary) {
        rescheduleForm.clearErrors();
        rescheduleForm.setData({ starts_at: '', ends_at: '' });
        setRescheduling(booking);
    }

    function submitReschedule(event: FormEvent) {
        event.preventDefault();
        if (!rescheduling) {
            return;
        }
        rescheduleForm.post(`/bookings/${rescheduling.id}/reschedule`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t('admin.bookings.toast.rescheduled'));
                setRescheduling(null);
            },
        });
    }

    const isEmpty = bookings.data.length === 0;
    const hasFilters = Boolean(
        filters.status ||
            filters.staff_id ||
            filters.service_id ||
            filters.from ||
            filters.to,
    );

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.bookings.title') }]}>
            <Head title={t('admin.bookings.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.bookings.title')}
                    description={t('admin.bookings.subtitle')}
                />

                <form
                    onSubmit={submitFilters}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="filter-status">
                            {t('admin.bookings.field.status')}
                        </Label>
                        <select
                            id="filter-status"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                        >
                            <option value="">
                                {t('admin.bookings.all_statuses')}
                            </option>
                            {options.statuses.map((s) => (
                                <option key={s} value={s}>
                                    {t(`booking_status.${s}`)}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="filter-staff">
                            {t('admin.bookings.field.staff')}
                        </Label>
                        <select
                            id="filter-staff"
                            value={staffId}
                            onChange={(e) => setStaffId(e.target.value)}
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                        >
                            <option value="">
                                {t('admin.bookings.all_staff')}
                            </option>
                            {options.staff.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="filter-service">
                            {t('admin.bookings.field.service')}
                        </Label>
                        <select
                            id="filter-service"
                            value={serviceId}
                            onChange={(e) => setServiceId(e.target.value)}
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                        >
                            <option value="">
                                {t('admin.bookings.all_services')}
                            </option>
                            {options.services.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="filter-from">
                            {t('admin.bookings.field.from')}
                        </Label>
                        <Input
                            id="filter-from"
                            type="date"
                            value={from}
                            onChange={(e) => setFrom(e.target.value)}
                            className="w-auto"
                        />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="filter-to">
                            {t('admin.bookings.field.to')}
                        </Label>
                        <Input
                            id="filter-to"
                            type="date"
                            value={to}
                            onChange={(e) => setTo(e.target.value)}
                            className="w-auto"
                        />
                    </div>

                    <Button type="submit" variant="outline">
                        {t('admin.bookings.filter')}
                    </Button>
                    {hasFilters ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={clearFilters}
                        >
                            {t('admin.bookings.clear')}
                        </Button>
                    ) : null}
                </form>

                {isEmpty ? (
                    <EmptyState
                        icon={CalendarCheckIcon}
                        title={
                            hasFilters
                                ? t('admin.bookings.empty_filtered')
                                : t('admin.bookings.empty')
                        }
                        description=""
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        {t('admin.bookings.field.customer')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('admin.bookings.field.staff')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('admin.bookings.field.starts_at')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('admin.bookings.field.status')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('admin.bookings.field.price')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('admin.common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {bookings.data.map((booking) => {
                                    const actions = bookingQuickActions(
                                        booking,
                                        can,
                                    );
                                    const canReschedule = canRescheduleBooking(
                                        booking,
                                        can,
                                    );

                                    return (
                                        <tr
                                            key={booking.id}
                                            className="border-t border-border"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/bookings/${booking.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {booking.customer ?? '—'}
                                                </Link>
                                                {booking.is_guest && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2 align-middle text-[10px]"
                                                    >
                                                        {t('admin.bookings.guest')}
                                                    </Badge>
                                                )}
                                                <div className="text-xs text-muted-foreground">
                                                    {booking.service ?? '—'}
                                                    <span className="ml-2 font-mono">
                                                        {booking.code}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {booking.staff ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {booking.starts_at
                                                    ? formatDateTime(
                                                          booking.starts_at,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline">
                                                    {t(
                                                        `booking_status.${booking.status}`,
                                                    )}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {formatMoney(
                                                    booking.price_minor,
                                                    booking.currency,
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex justify-end gap-1">
                                                    {canReschedule ? (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                openReschedule(
                                                                    booking,
                                                                )
                                                            }
                                                            aria-label={t(
                                                                'admin.bookings.action.reschedule',
                                                            )}
                                                            title={t(
                                                                'admin.bookings.action.reschedule',
                                                            )}
                                                        >
                                                            <CalendarClockIcon className="size-4" />
                                                        </Button>
                                                    ) : null}
                                                    {actions.map((action) => {
                                                        const keys =
                                                            bookingActionKeys(
                                                                action,
                                                            );
                                                        const Icon =
                                                            ACTION_ICON[action];

                                                        return (
                                                            <Button
                                                                key={action}
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() =>
                                                                    askConfirm(
                                                                        {
                                                                            url: bookingActionUrl(
                                                                                action,
                                                                                booking.id,
                                                                            ),
                                                                            title: t(
                                                                                keys.label,
                                                                            ),
                                                                            message:
                                                                                t(
                                                                                    keys.confirm,
                                                                                ),
                                                                        },
                                                                        t(
                                                                            keys.toast,
                                                                        ),
                                                                    )
                                                                }
                                                                aria-label={t(
                                                                    keys.label,
                                                                )}
                                                                title={t(
                                                                    keys.label,
                                                                )}
                                                            >
                                                                <Icon
                                                                    className={
                                                                        action ===
                                                                        'cancel'
                                                                            ? 'size-4 text-destructive'
                                                                            : 'size-4'
                                                                    }
                                                                />
                                                            </Button>
                                                        );
                                                    })}
                                                    <Button size="sm" asChild>
                                                        <Link
                                                            href={`/bookings/${booking.id}`}
                                                        >
                                                            {t(
                                                                'admin.common.view',
                                                            )}
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {bookings.last_page > 1 ? (
                    <div className="flex flex-wrap gap-1">
                        {bookings.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
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
            </div>

            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={confirm?.title ?? ''}
                description={confirm?.message}
                confirmLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onConfirm={runConfirm}
                destructive
            />

            <FormSheet
                open={rescheduling !== null}
                onOpenChange={(open) => !open && setRescheduling(null)}
                title={t('admin.bookings.card.reschedule_title')}
                description={t('admin.bookings.card.reschedule_desc')}
                submitLabel={t('admin.bookings.action.reschedule')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submitReschedule}
                submitting={rescheduleForm.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="reschedule-starts">
                        {t('admin.bookings.field.from')}
                    </Label>
                    <Input
                        id="reschedule-starts"
                        type="datetime-local"
                        value={rescheduleForm.data.starts_at}
                        onChange={(e) =>
                            rescheduleForm.setData('starts_at', e.target.value)
                        }
                        autoFocus
                    />
                    {rescheduleForm.errors.starts_at ? (
                        <p className="text-sm text-destructive">
                            {rescheduleForm.errors.starts_at}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="reschedule-ends">
                        {t('admin.bookings.field.to')}
                    </Label>
                    <Input
                        id="reschedule-ends"
                        type="datetime-local"
                        value={rescheduleForm.data.ends_at}
                        onChange={(e) =>
                            rescheduleForm.setData('ends_at', e.target.value)
                        }
                    />
                    {rescheduleForm.errors.ends_at ? (
                        <p className="text-sm text-destructive">
                            {rescheduleForm.errors.ends_at}
                        </p>
                    ) : null}
                </div>
            </FormSheet>
        </AdminLayout>
    );
}
