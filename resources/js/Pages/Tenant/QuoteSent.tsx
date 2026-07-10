import { Head, Link } from '@inertiajs/react';
import { FileTextIcon } from 'lucide-react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

type QuoteSentProps = {
    quote: {
        service: string;
    };
};

/**
 * Quote request confirmation (SLO-102): reached after a guest submits the public
 * quote form. The request has no public code — the tenant answers by email — so
 * the page only acknowledges what was asked for.
 */
export default function QuoteSent({ quote }: QuoteSentProps) {
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('tenant.quote_sent.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 py-16 sm:px-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <FileTextIcon className="size-12 text-primary" />
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.quote_sent.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('tenant.quote_sent.subtitle')}
                    </p>
                </div>

                <dl className="grid gap-px overflow-hidden rounded-xl border border-border bg-border">
                    <div className="flex items-center justify-between bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.quote_sent.service')}
                        </dt>
                        <dd className="font-medium">{quote.service}</dd>
                    </div>
                </dl>

                <div className="flex justify-center">
                    <Button asChild>
                        <Link href="/">{t('tenant.quote_sent.back')}</Link>
                    </Button>
                </div>
            </div>
        </PublicLayout>
    );
}
