import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type ResetPasswordProps = {
    email: string;
    token: string;
};

export default function ResetPassword({ email, token }: ResetPasswordProps) {
    const t = useTranslations();
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/reset-password', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthLayout title={t('auth.reset.title')} subtitle={t('auth.reset.subtitle')}>
            <Head title={t('auth.reset.title')} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">{t('auth.reset.email')}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={form.data.email}
                        autoComplete="username"
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    {form.errors.email ? (
                        <p className="text-sm text-red-500">{form.errors.email}</p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password">{t('auth.reset.password')}</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        autoComplete="new-password"
                        autoFocus
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                    {form.errors.password ? (
                        <p className="text-sm text-red-500">{form.errors.password}</p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password_confirmation">
                        {t('auth.reset.password_confirmation')}
                    </Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={form.data.password_confirmation}
                        autoComplete="new-password"
                        onChange={(e) =>
                            form.setData('password_confirmation', e.target.value)
                        }
                    />
                    {form.errors.password_confirmation ? (
                        <p className="text-sm text-red-500">
                            {form.errors.password_confirmation}
                        </p>
                    ) : null}
                </div>

                <Button type="submit" className="mt-2 w-full" disabled={form.processing}>
                    {t('auth.reset.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
