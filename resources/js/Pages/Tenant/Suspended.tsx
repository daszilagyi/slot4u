import { Head } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { useTranslations } from '@/lib/i18n';

interface SuspendedProps {
    tenantName: string;
}

export default function Suspended({ tenantName }: SuspendedProps) {
    const t = useTranslations();

    return (
        <AppLayout>
            <Head title={t('tenant.suspended.title')} />

            <div className="mx-auto flex max-w-xl flex-col items-center gap-6 text-center">
                <span className="rounded-full border border-destructive/40 px-4 py-1 text-xs font-medium tracking-wide text-destructive uppercase">
                    {t('tenant.suspended.badge')}
                </span>

                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                    {t('tenant.suspended.title')}
                </h1>

                <p className="text-lg text-muted-foreground">
                    {t('tenant.suspended.subtitle')}
                </p>

                <p className="text-sm text-muted-foreground/70">{tenantName}</p>
            </div>
        </AppLayout>
    );
}
