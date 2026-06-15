import { Head } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

export default function Welcome() {
    const t = useTranslations();

    return (
        <AppLayout>
            <Head title={t('welcome.title')} />

            <div className="mx-auto flex max-w-xl flex-col items-center gap-6 text-center">
                <span className="rounded-full border border-border px-4 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('welcome.badge')}
                </span>

                <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
                    {t('welcome.title')}
                </h1>

                <p className="text-lg text-muted-foreground">
                    {t('welcome.subtitle')}
                </p>

                <Button size="lg">{t('welcome.title')}</Button>
            </div>
        </AppLayout>
    );
}
