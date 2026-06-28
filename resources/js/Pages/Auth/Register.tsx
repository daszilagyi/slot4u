import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type RegisterProps = {
    centralDomain: string;
};

/** Mirror of the backend slug rule: lowercase, alphanumeric, hyphen-separated. */
function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export default function Register({ centralDomain }: RegisterProps) {
    const t = useTranslations();
    const [slugEdited, setSlugEdited] = useState(false);
    const form = useForm({
        company_name: '',
        slug: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function onCompanyName(value: string) {
        form.setData((data) => ({
            ...data,
            company_name: value,
            slug: slugEdited ? data.slug : slugify(value),
        }));
    }

    function onSlug(value: string) {
        setSlugEdited(true);
        form.setData('slug', slugify(value));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/register', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthLayout
            title={t('auth.register.title')}
            subtitle={t('auth.register.subtitle')}
            footer={
                <Link href="/login" className="hover:text-foreground">
                    {t('auth.register.has_account')}
                </Link>
            }
        >
            <Head title={t('auth.register.title')} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="company_name">{t('auth.register.company_name')}</Label>
                    <Input
                        id="company_name"
                        name="company_name"
                        value={form.data.company_name}
                        autoFocus
                        onChange={(e) => onCompanyName(e.target.value)}
                    />
                    {form.errors.company_name ? (
                        <p className="text-sm text-red-500">{form.errors.company_name}</p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="slug">{t('auth.register.slug')}</Label>
                    <Input
                        id="slug"
                        name="slug"
                        value={form.data.slug}
                        onChange={(e) => onSlug(e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('auth.register.slug_hint', {
                            host: `${form.data.slug || t('auth.register.slug_placeholder')}.${centralDomain}`,
                        })}
                    </p>
                    {form.errors.slug ? (
                        <p className="text-sm text-red-500">{form.errors.slug}</p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="name">{t('auth.register.name')}</Label>
                    <Input
                        id="name"
                        name="name"
                        value={form.data.name}
                        autoComplete="name"
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    {form.errors.name ? (
                        <p className="text-sm text-red-500">{form.errors.name}</p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">{t('auth.register.email')}</Label>
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
                    <Label htmlFor="password">{t('auth.register.password')}</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        autoComplete="new-password"
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                    {form.errors.password ? (
                        <p className="text-sm text-red-500">{form.errors.password}</p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password_confirmation">
                        {t('auth.register.password_confirmation')}
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
                </div>

                <Button type="submit" className="mt-2 w-full" disabled={form.processing}>
                    {t('auth.register.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
