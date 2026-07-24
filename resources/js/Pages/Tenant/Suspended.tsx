import { Head, Link } from '@inertiajs/react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

interface SuspendedProps {
    tenantName: string;
    canAccessBilling?: boolean;
}

export default function Suspended({ tenantName, canAccessBilling = false }: SuspendedProps) {
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('tenant.suspended.title')} />

            <div className="mx-auto flex max-w-xl flex-col items-center gap-6 px-4 py-24 text-center">
                <span className="rounded-full border border-destructive/40 px-4 py-1 text-xs font-medium tracking-wide text-destructive uppercase">
                    {t('tenant.suspended.badge')}
                </span>

                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                    {t('tenant.suspended.title')}
                </h1>

                <p className="text-lg text-muted-foreground">
                    {t('tenant.suspended.subtitle')}
                </p>

                {canAccessBilling ? (
                    <div className="flex flex-col items-center gap-2">
                        <Button asChild>
                            <Link href="/billing">{t('tenant.suspended.billing_cta')}</Link>
                        </Button>
                        <p className="text-sm text-muted-foreground/70">
                            {t('tenant.suspended.billing_hint')}
                        </p>
                    </div>
                ) : null}

                <p className="text-sm text-muted-foreground/70">{tenantName}</p>
            </div>
        </PublicLayout>
    );
}
