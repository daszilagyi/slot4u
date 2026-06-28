import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

export default function Dashboard() {
    const t = useTranslations();
    const logout = useForm({});

    function submitLogout(event: FormEvent) {
        event.preventDefault();
        logout.post('/logout');
    }

    return (
        <AppLayout>
            <Head title={t('tenant.dashboard.title')} />

            <div className="mx-auto flex max-w-xl flex-col items-center gap-6 text-center">
                <span className="rounded-full border border-border px-4 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('tenant.dashboard.badge')}
                </span>

                <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
                    {t('tenant.dashboard.title')}
                </h1>

                <p className="text-lg text-muted-foreground">
                    {t('tenant.dashboard.subtitle')}
                </p>

                <form onSubmit={submitLogout}>
                    <Button type="submit" variant="outline">
                        {t('tenant.dashboard.logout')}
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
