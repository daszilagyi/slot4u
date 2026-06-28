import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

export default function Login() {
    const t = useTranslations();
    const { status } = usePage().props;
    const form = useForm({ email: '', password: '', remember: false });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    return (
        <AuthLayout
            title={t('auth.login.title')}
            subtitle={t('auth.login.subtitle')}
            footer={
                <div className="flex flex-col gap-1">
                    <Link href="/forgot-password" className="hover:text-foreground">
                        {t('auth.login.forgot')}
                    </Link>
                    <Link href="/register" className="hover:text-foreground">
                        {t('auth.login.register')}
                    </Link>
                </div>
            }
        >
            <Head title={t('auth.login.title')} />

            {status ? (
                <p className="mb-4 text-sm font-medium text-primary">{status}</p>
            ) : null}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">{t('auth.login.email')}</Label>
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

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password">{t('auth.login.password')}</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        autoComplete="current-password"
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                    {form.errors.password ? (
                        <p className="text-sm text-red-500">
                            {form.errors.password}
                        </p>
                    ) : null}
                </div>

                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                    <input
                        type="checkbox"
                        name="remember"
                        checked={form.data.remember}
                        onChange={(e) => form.setData('remember', e.target.checked)}
                        className="size-4 rounded border-input"
                    />
                    {t('auth.login.remember')}
                </label>

                <Button type="submit" className="mt-2 w-full" disabled={form.processing}>
                    {t('auth.login.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
