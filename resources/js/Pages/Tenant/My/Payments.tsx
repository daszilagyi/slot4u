import { Head } from '@inertiajs/react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { MyPayment } from '@/types';

type PaymentsProps = {
    payments: MyPayment[];
};

/**
 * Members area — the customer's own online payments and refunds (SLO-132).
 * Read-only: what was charged, for which booking, and what has come back. The
 * gateway payload and the tenant's internal refund notes never reach this page.
 */
export default function Payments({ payments }: PaymentsProps) {
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('tenant.my_payments.title')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my_payments.title')}
                    </h1>
                </header>

                {payments.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        {t('tenant.my_payments.empty')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {payments.map((payment) => (
                            <li
                                key={payment.id}
                                className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4"
                            >
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                {payment.service_name ?? '—'}
                                            </span>
                                            <Badge variant="outline">
                                                {t(
                                                    `tenant.my_payments.status.${payment.status}`,
                                                )}
                                            </Badge>
                                        </div>
                                        {payment.booking_starts_local ? (
                                            <span className="text-sm text-muted-foreground">
                                                {t(
                                                    'tenant.my_payments.booking_when',
                                                )}{' '}
                                                {payment.booking_starts_local}
                                            </span>
                                        ) : null}
                                        <span className="text-sm text-muted-foreground">
                                            {t('tenant.my_payments.code')}:{' '}
                                            <span className="font-mono">
                                                {payment.booking_code ?? '—'}
                                            </span>
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {payment.paid_local
                                                ? t(
                                                      'tenant.my_payments.paid_at',
                                                      { date: payment.paid_local },
                                                  )
                                                : t(
                                                      'tenant.my_payments.started_at',
                                                      {
                                                          date:
                                                              payment.created_local ??
                                                              '—',
                                                      },
                                                  )}
                                        </span>
                                    </div>

                                    <div className="flex shrink-0 flex-col items-start gap-0.5 sm:items-end">
                                        <span className="text-xs text-muted-foreground uppercase">
                                            {t('tenant.my_payments.amount')}
                                        </span>
                                        <span className="text-lg font-semibold text-primary">
                                            {formatMoney(
                                                payment.amount_minor,
                                                payment.currency,
                                            )}
                                        </span>
                                    </div>
                                </div>

                                {payment.refunds.length > 0 ? (
                                    <ul className="flex flex-col gap-1 border-t border-border pt-3">
                                        {payment.refunds.map((refund) => (
                                            <li
                                                key={refund.id}
                                                className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                                            >
                                                <span>
                                                    {t(
                                                        'tenant.my_payments.refunded',
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
                                                        `tenant.my_payments.refund_status.${refund.status}`,
                                                    )}
                                                </Badge>
                                                {refund.refunded_local ? (
                                                    <span>
                                                        {refund.refunded_local}
                                                    </span>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
