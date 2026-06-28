import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

export default function VerifyEmail() {
    const t = useTranslations();
    const { status } = usePage().props;
    const resend = useForm({});
    const logout = useForm({});

    function submitResend(event: FormEvent) {
        event.preventDefault();
        resend.post('/email/verification-notification');
    }

    function submitLogout(event: FormEvent) {
        event.preventDefault();
        logout.post('/logout');
    }

    return (
        <AuthLayout title={t('auth.verify.title')} subtitle={t('auth.verify.subtitle')}>
            <Head title={t('auth.verify.title')} />

            {status === 'verification-link-sent' ? (
                <p className="mb-4 text-sm font-medium text-primary">
                    {t('auth.verify.sent')}
                </p>
            ) : null}

            <div className="flex flex-col gap-3">
                <form onSubmit={submitResend}>
                    <Button type="submit" className="w-full" disabled={resend.processing}>
                        {t('auth.verify.resend')}
                    </Button>
                </form>

                <form onSubmit={submitLogout}>
                    <Button
                        type="submit"
                        variant="ghost"
                        className="w-full"
                        disabled={logout.processing}
                    >
                        {t('auth.verify.logout')}
                    </Button>
                </form>
            </div>
        </AuthLayout>
    );
}
