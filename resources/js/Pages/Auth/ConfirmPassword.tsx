import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

/**
 * The wall in front of the second-factor settings (SLO-149).
 *
 * Asked of somebody who is already signed in, which looks redundant until you
 * name the threat: two-factor exists for the case where somebody else is holding
 * the session. Without this, that same session could turn the second factor off
 * or read the recovery codes, and the protection would be worth nothing.
 */
export default function ConfirmPassword() {
    const t = useTranslations();
    const form = useForm({ password: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/user/confirm-password', {
            onFinish: () => form.reset('password'),
        });
    }

    return (
        <AuthLayout
            title={t('auth.confirm_password.title')}
            subtitle={t('auth.confirm_password.subtitle')}
        >
            <Head title={t('auth.confirm_password.title')} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="password">
                        {t('auth.confirm_password.password')}
                    </Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        autoComplete="current-password"
                        autoFocus
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                    {form.errors.password ? (
                        <p className="text-sm text-red-500">
                            {form.errors.password}
                        </p>
                    ) : null}
                </div>

                <Button type="submit" disabled={form.processing}>
                    {t('auth.confirm_password.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
