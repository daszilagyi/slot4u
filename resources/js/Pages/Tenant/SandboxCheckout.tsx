import { Head, useForm } from '@inertiajs/react';
import { CreditCardIcon } from 'lucide-react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { SandboxCheckoutPayment } from '@/types';

type SandboxCheckoutProps = {
    payment: SandboxCheckoutPayment;
    booking: { code: string; service: string | null };
};

/**
 * The sandbox gateway's checkout screen (SLO-130): what a real provider would host
 * on its own domain, so the pending_payment → confirmed flow (and the refused-card
 * path) can be demoed and tested without a merchant account. The route only exists
 * while `payments.sandbox.enabled` is on — never in production.
 */
export default function SandboxCheckout({
    payment,
    booking,
}: SandboxCheckoutProps) {
    const t = useTranslations();
    const form = useForm({ outcome: 'paid' });

    const submit = (outcome: 'paid' | 'failed') => {
        form.transform(() => ({ outcome }));
        form.post(`/payments/sandbox/${payment.reference}`);
    };

    return (
        <PublicLayout>
            <Head title={t('tenant.sandbox_checkout.title')} />

            <div className="mx-auto flex w-full max-w-md flex-col gap-6 px-4 py-16 sm:px-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <CreditCardIcon className="size-12 text-primary" />
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.sandbox_checkout.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('tenant.sandbox_checkout.subtitle')}
                    </p>
                    <Badge variant="outline">
                        {t('tenant.sandbox_checkout.badge')}
                    </Badge>
                </div>

                <dl className="grid gap-px overflow-hidden rounded-xl border border-border bg-border">
                    <div className="flex items-center justify-between bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.sandbox_checkout.service')}
                        </dt>
                        <dd className="font-medium">{booking.service ?? '—'}</dd>
                    </div>
                    <div className="flex items-center justify-between bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.sandbox_checkout.code')}
                        </dt>
                        <dd className="font-mono font-medium">
                            {booking.code}
                        </dd>
                    </div>
                    <div className="flex items-center justify-between bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.sandbox_checkout.amount')}
                        </dt>
                        <dd className="font-medium">
                            {formatMoney(payment.amount_minor, payment.currency)}
                        </dd>
                    </div>
                </dl>

                <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
                    <Button disabled={form.processing} onClick={() => submit('paid')}>
                        {t('tenant.sandbox_checkout.pay')}
                    </Button>
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => submit('failed')}
                    >
                        {t('tenant.sandbox_checkout.decline')}
                    </Button>
                </div>
            </div>
        </PublicLayout>
    );
}
