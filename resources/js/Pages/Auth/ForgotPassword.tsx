import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

export default function ForgotPassword() {
    const t = useTranslations();
    const { status } = usePage().props;
    const form = useForm({ email: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/forgot-password');
    }

    return (
        <AuthLayout
            title={t('auth.forgot.title')}
            subtitle={t('auth.forgot.subtitle')}
            footer={
                <Link href="/login" className="hover:text-foreground">
                    {t('auth.forgot.back')}
                </Link>
            }
        >
            <Head title={t('auth.forgot.title')} />

            {status ? (
                <p className="mb-4 text-sm font-medium text-primary">{status}</p>
            ) : null}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">{t('auth.forgot.email')}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={form.data.email}
                        autoComplete="username"
                        autoFocus
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    {form.errors.email ? (
                        <p className="text-sm text-red-500">{form.errors.email}</p>
                    ) : null}
                </div>

                <Button type="submit" className="mt-2 w-full" disabled={form.processing}>
                    {t('auth.forgot.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
