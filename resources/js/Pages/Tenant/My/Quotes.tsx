import { Head } from '@inertiajs/react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { MyQuoteRequest } from '@/types';

type QuotesProps = {
    quotes: MyQuoteRequest[];
};

/**
 * Members area — the customer's own quote requests (SLO-98). Read-only: service,
 * status and — once the tenant has worked up a price — the quoted amount and its
 * validity. The admin's internal notes are never sent to this page.
 */
export default function Quotes({ quotes }: QuotesProps) {
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('tenant.my_quotes.title')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my_quotes.title')}
                    </h1>
                </header>

                {quotes.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        {t('tenant.my_quotes.empty')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {quotes.map((quote) => (
                            <li
                                key={quote.id}
                                className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex flex-col gap-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {quote.service_name ?? '—'}
                                        </span>
                                        <Badge variant="outline">
                                            {t(
                                                `tenant.quote_status.${quote.status}`,
                                            )}
                                        </Badge>
                                    </div>
                                    {quote.created_local ? (
                                        <span className="text-sm text-muted-foreground">
                                            {t('tenant.my_quotes.submitted')}:{' '}
                                            {quote.created_local}
                                        </span>
                                    ) : null}
                                    {quote.valid_until_local ? (
                                        <span className="text-sm text-muted-foreground">
                                            {t('tenant.my_quotes.valid_until')}{' '}
                                            {quote.valid_until_local}
                                        </span>
                                    ) : null}
                                </div>

                                {quote.price_minor != null ? (
                                    <div className="flex shrink-0 flex-col items-start gap-0.5 sm:items-end">
                                        <span className="text-xs text-muted-foreground uppercase">
                                            {t('tenant.my_quotes.price')}
                                        </span>
                                        <span className="text-lg font-semibold text-primary">
                                            {formatMoney(
                                                quote.price_minor,
                                                quote.currency ?? 'HUF',
                                            )}
                                        </span>
                                    </div>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
