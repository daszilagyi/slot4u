import { Head, Link } from '@inertiajs/react';
import { ListOrderedIcon } from 'lucide-react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

type WaitlistedProps = {
    waitlist: {
        position: number;
        service: string;
        starts_local: string | null;
    };
};

/**
 * Waitlist join confirmation (SLO-100): reached after a guest joins a full
 * event's FIFO waitlist, showing their position.
 */
export default function Waitlisted({ waitlist }: WaitlistedProps) {
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('tenant.waitlisted.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 py-16 sm:px-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <ListOrderedIcon className="size-12 text-primary" />
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.waitlisted.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('tenant.waitlisted.subtitle')}
                    </p>
                </div>

                <div className="flex flex-col items-center gap-1 rounded-xl border border-border bg-card px-4 py-6 text-center">
                    <span className="text-xs text-muted-foreground uppercase">
                        {t('tenant.waitlisted.position_label')}
                    </span>
                    <Badge
                        variant="outline"
                        className="font-mono text-base tracking-widest"
                    >
                        {waitlist.position}
                    </Badge>
                </div>

                <dl className="grid gap-px overflow-hidden rounded-xl border border-border bg-border">
                    <div className="flex items-center justify-between bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.waitlisted.event')}
                        </dt>
                        <dd className="font-medium">
                            {waitlist.service}
                            {waitlist.starts_local
                                ? ` · ${waitlist.starts_local}`
                                : ''}
                        </dd>
                    </div>
                </dl>

                <div className="flex justify-center">
                    <Button asChild>
                        <Link href="/">{t('tenant.waitlisted.back')}</Link>
                    </Button>
                </div>
            </div>
        </PublicLayout>
    );
}
