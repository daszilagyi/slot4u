import { Head } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { useTranslations } from '@/lib/i18n';

export default function TenantHome() {
    const t = useTranslations();

    return (
        <AppLayout>
            <Head title={t('tenant.home.title')} />

            <div className="mx-auto flex max-w-xl flex-col items-center gap-6 text-center">
                <span className="rounded-full border border-border px-4 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('tenant.home.badge')}
                </span>

                <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
                    {t('tenant.home.title')}
                </h1>

                <p className="text-lg text-muted-foreground">
                    {t('tenant.home.subtitle')}
                </p>
            </div>
        </AppLayout>
    );
}
