import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

/**
 * Customer self-registration on a tenant subdomain (SLO-95). The Fortify
 * `/register` route is host-aware: on `{tenant}.{central}` it creates a
 * customer for that tenant and lands them in the members area.
 */
export default function RegisterCustomer() {
    const t = useTranslations();
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/register', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthLayout
            title={t('auth.register_customer.title')}
            subtitle={t('auth.register_customer.subtitle')}
            footer={
                <Link href="/login" className="hover:text-foreground">
                    {t('auth.register_customer.has_account')}
                </Link>
            }
        >
            <Head title={t('auth.register_customer.title')} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="name">
                        {t('auth.register_customer.name')}
                    </Label>
                    <Input
                        id="name"
                        name="name"
                        value={form.data.name}
                        autoComplete="name"
                        autoFocus
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    {form.errors.name ? (
                        <p className="text-sm text-red-500">
                            {form.errors.name}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">
                        {t('auth.register_customer.email')}
                    </Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={form.data.email}
                        autoComplete="username"
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    {form.errors.email ? (
                        <p className="text-sm text-red-500">
                            {form.errors.email}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="phone">
                        {t('auth.register_customer.phone_optional')}
                    </Label>
                    <Input
                        id="phone"
                        type="tel"
                        name="phone"
                        value={form.data.phone}
                        autoComplete="tel"
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                    {form.errors.phone ? (
                        <p className="text-sm text-red-500">
                            {form.errors.phone}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password">
                        {t('auth.register_customer.password')}
                    </Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        autoComplete="new-password"
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                    />
                    {form.errors.password ? (
                        <p className="text-sm text-red-500">
                            {form.errors.password}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password_confirmation">
                        {t('auth.register_customer.password_confirmation')}
                    </Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={form.data.password_confirmation}
                        autoComplete="new-password"
                        onChange={(e) =>
                            form.setData(
                                'password_confirmation',
                                e.target.value,
                            )
                        }
                    />
                </div>

                <Button
                    type="submit"
                    className="mt-2 w-full"
                    disabled={form.processing}
                >
                    {t('auth.register_customer.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
