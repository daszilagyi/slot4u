import { Head, Link } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

export default function SuperDashboard() {
    const t = useTranslations();

    return (
        <AppLayout>
            <Head title={t('super.dashboard.title')} />

            <div className="mx-auto flex max-w-xl flex-col items-center gap-6 text-center">
                <span className="rounded-full border border-border px-4 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('super.dashboard.badge')}
                </span>

                <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
                    {t('super.dashboard.title')}
                </h1>

                <p className="text-lg text-muted-foreground">
                    {t('super.dashboard.subtitle')}
                </p>

                <div className="flex flex-wrap justify-center gap-3">
                    <Button asChild size="lg">
                        <Link href="/tenants">{t('super.dashboard.tenants_link')}</Link>
                    </Button>
                    <Button asChild size="lg" variant="outline">
                        <Link href="/audit-logs">{t('super.audit.title')}</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
