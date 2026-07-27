import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, PencilIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { BookingDetail, BookingInvoice, BookingPayment } from '@/types';

type ShowProps = {
    booking: BookingDetail;
    payments: BookingPayment[];
    refundable_minor: number;
    invoice: BookingInvoice | null;
    can: { edit: boolean; cancel: boolean };
};

export default function BookingShow({
    booking,
    payments,
    refundable_minor: refundableMinor,
    invoice,
    can,
}: ShowProps) {
    const t = useTranslations();
    // The amount is entered in major units (what an admin thinks in) and sent as
    // minor units — money never travels as a float.
    const refundForm = useForm({ amount_minor: 0, reason: '' });

    const submitRefund = (event: React.FormEvent) => {
        event.preventDefault();
        refundForm.post(`/bookings/${booking.id}/refund`, {
            preserveScroll: true,
            onSuccess: () => {
                refundForm.reset();
                toast.success(t('admin.bookings.toast_refunded'));
            },
        });
    };

    const rows: { label: string; value: string }[] = [
        {
            label: t('admin.bookings.field.customer'),
            value: booking.customer
                ? booking.is_guest
                    ? `${booking.customer} (${t('admin.bookings.guest')})`
                    : booking.customer
                : '—',
        },
        {
            label: t('admin.bookings.field.customer_email'),
            value: booking.customer_email ?? '—',
        },
        {
            label: t('admin.bookings.field.customer_phone'),
            value: booking.customer_phone ?? '—',
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

                    {can.edit ? (
                        <PriceEditor
                            booking={booking}
                            payments={payments}
                            invoice={invoice}
                        />
                    ) : null}
                </div>

                <div className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">
                        {t('admin.bookings.payments.title')}
                    </h2>

                    {payments.length === 0 ? (
                        <p className="rounded-xl border border-border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
                            {t('admin.bookings.payments.empty')}
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {payments.map((payment) => (
                                <li
                                    key={payment.id}
                                    className="flex flex-col gap-2 rounded-lg border border-border bg-card px-4 py-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {formatMoney(
                                                payment.amount_minor,
                                                payment.currency,
                                            )}
                                        </span>
                                        <Badge variant="outline">
                                            {t(
                                                `admin.bookings.payments.status.${payment.status}`,
                                            )}
                                        </Badge>
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            {payment.paid_at
                                                ? t(
                                                      'admin.bookings.payments.paid_at',
                                                      {
                                                          date: formatDateTime(
                                                              payment.paid_at,
                                                          ),
                                                      },
                                                  )
                                                : payment.provider}
                                        </span>
                                    </div>

                                    {payment.refunds.map((refund) => (
                                        <div
                                            key={refund.id}
                                            className="flex flex-wrap items-center gap-2 border-t border-border pt-2 text-xs text-muted-foreground"
                                        >
                                            <span>
                                                {t(
                                                    'admin.bookings.payments.refunded_row',
                                                    {
                                                        amount: formatMoney(
                                                            refund.amount_minor,
                                                            refund.currency,
                                                        ),
                                                    },
                                                )}
                                            </span>
                                            <Badge variant="outline">
                                                {t(
                                                    `admin.bookings.payments.status.${refund.status}`,
                                                )}
                                            </Badge>
                                            {refund.reason ? (
                                                <span>{refund.reason}</span>
                                            ) : null}
                                        </div>
                                    ))}
                                </li>
                            ))}
                        </ul>
                    )}

                    {can.cancel && payments.length > 0 ? (
                        refundableMinor > 0 ? (
                            <form
                                onSubmit={submitRefund}
                                className="flex flex-col gap-3 rounded-xl border border-border bg-card px-4 py-4"
                            >
                                <div>
                                    <h3 className="text-sm font-semibold">
                                        {t(
                                            'admin.bookings.payments.refund_title',
                                        )}
                                    </h3>
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'admin.bookings.payments.refund_desc',
                                            {
                                                amount: formatMoney(
                                                    refundableMinor,
                                                    booking.currency,
                                                ),
                                            },
                                        )}
                                    </p>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="refund_amount">
                                            {t(
                                                'admin.bookings.payments.refund_amount',
                                            )}
                                        </Label>
                                        <Input
                                            id="refund_amount"
                                            type="number"
                                            min={1}
                                            max={Math.floor(
                                                refundableMinor / 100,
                                            )}
                                            value={
                                                refundForm.data.amount_minor
                                                    ? refundForm.data
                                                          .amount_minor / 100
                                                    : ''
                                            }
                                            onChange={(event) =>
                                                refundForm.setData(
                                                    'amount_minor',
                                                    Math.round(
                                                        Number(
                                                            event.target
                                                                .value || 0,
                                                        ) * 100,
                                                    ),
                                                )
                                            }
                                        />
                                        {refundForm.errors.amount_minor ? (
                                            <p className="text-xs text-destructive">
                                                {refundForm.errors.amount_minor}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="refund_reason">
                                            {t(
                                                'admin.bookings.payments.refund_reason',
                                            )}
                                        </Label>
                                        <Input
                                            id="refund_reason"
                                            value={refundForm.data.reason}
                                            onChange={(event) =>
                                                refundForm.setData(
                                                    'reason',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <Button
                                    type="submit"
                                    className="w-fit"
                                    disabled={
                                        refundForm.processing ||
                                        refundForm.data.amount_minor <= 0
                                    }
                                >
                                    {t('admin.bookings.payments.refund_submit')}
                                </Button>
                            </form>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'admin.bookings.payments.nothing_refundable',
                                )}
                            </p>
                        )
                    ) : null}
                </div>

                {invoice ? (
                    <div className="flex flex-col gap-3">
                        <h2 className="text-lg font-semibold">
                            {t('admin.bookings.invoice.title')}
                        </h2>

                        <div className="flex flex-col gap-2 rounded-xl border border-border bg-card px-4 py-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono font-medium">
                                    {invoice.number ??
                                        t('admin.bookings.invoice.none')}
                                </span>
                                <Badge variant="outline">
                                    {t(
                                        `admin.bookings.invoice.status.${invoice.status}`,
                                    )}
                                </Badge>
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {invoice.issued_at
                                        ? t('admin.bookings.invoice.issued_at', {
                                              date: formatDateTime(
                                                  invoice.issued_at,
                                              ),
                                          })
                                        : formatMoney(
                                              invoice.amount_minor,
                                              invoice.currency,
                                          )}
                                </span>
                            </div>

                            {invoice.error ? (
                                <p className="text-xs text-destructive">
                                    {invoice.error}
                                </p>
                            ) : null}

                            <div className="flex flex-wrap gap-2">
                                {invoice.has_pdf ? (
                                    <Button asChild variant="outline" size="sm">
                                        <a
                                            href={`/bookings/${booking.id}/invoices/${invoice.id}/pdf`}
                                        >
                                            {t(
                                                'admin.bookings.invoice.download',
                                            )}
                                        </a>
                                    </Button>
                                ) : null}
                                {can.edit && invoice.can_retry ? (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/bookings/${booking.id}/invoices/${invoice.id}/retry`,
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        toast.success(
                                                            t(
                                                                'admin.bookings.toast_invoice_retry',
                                                            ),
                                                        ),
                                                },
                                            )
                                        }
                                    >
                                        {t('admin.bookings.invoice.retry')}
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    </div>
                ) : null}

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

/**
 * Inline list-price editor (SLO-126). The price is the commission base, so the
 * copy says so — an admin lowering a price should know it moves what they owe.
 *
 * The two blocking cases are decided server-side; mirroring them here only
 * turns a 422 into an explanation the admin can read before typing.
 */
function PriceEditor({
    booking,
    payments,
    invoice,
}: {
    booking: BookingDetail;
    payments: BookingPayment[];
    invoice: BookingInvoice | null;
}) {
    const t = useTranslations();
    const [open, setOpen] = useState(false);

    const pendingPayment = payments.some(
        (payment) => payment.status === 'pending',
    );
    const issuedInvoice = invoice?.status === 'issued';
    const blocked = pendingPayment
        ? t('admin.bookings.price.blocked_by_payment')
        : issuedInvoice
          ? t('admin.bookings.price.blocked_by_invoice')
          : null;

    // Entered in major units (what an admin thinks in), sent as minor — money
    // never travels as a float.
    const form = useForm({ price_minor: booking.price_minor });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(`/bookings/${booking.id}/price`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                toast.success(t('admin.bookings.price.saved'));
            },
        });
    };

    if (blocked !== null) {
        return (
            <p className="text-xs text-muted-foreground">{blocked}</p>
        );
    }

    if (!open) {
        return (
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="w-fit gap-1.5"
                onClick={() => setOpen(true)}
            >
                <PencilIcon className="size-3.5" aria-hidden />
                {t('admin.bookings.price.edit')}
            </Button>
        );
    }

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4"
        >
            <Label htmlFor="price">{t('admin.bookings.price.label')}</Label>
            <div className="flex flex-col gap-2 sm:flex-row">
                <Input
                    id="price"
                    type="number"
                    min={0}
                    step={1}
                    value={form.data.price_minor / 100}
                    onChange={(event) =>
                        form.setData(
                            'price_minor',
                            Math.round(Number(event.target.value) * 100),
                        )
                    }
                />
                <Button type="submit" size="sm" disabled={form.processing}>
                    {t('admin.bookings.price.save')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setOpen(false)}
                >
                    {t('admin.bookings.price.cancel')}
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">
                {t('admin.bookings.price.hint')}
            </p>
            {form.errors.price_minor ? (
                <p className="text-xs text-destructive">
                    {form.errors.price_minor}
                </p>
            ) : null}
        </form>
    );
}
