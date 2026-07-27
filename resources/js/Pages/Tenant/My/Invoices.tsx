import { Head } from '@inertiajs/react';
import { DownloadIcon } from 'lucide-react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { MyInvoice } from '@/types';

type InvoicesProps = {
    invoices: MyInvoice[];
};

/**
 * Members area — the customer's own invoices (SLO-133). Only issued (and
 * stornoed) invoices appear: one still waiting on the provider is the tenant's
 * problem, not a document to promise. The PDF is streamed from a private disk
 * behind the same self-scope, never a public URL.
 */
export default function Invoices({ invoices }: InvoicesProps) {
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('tenant.my_invoices.title')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my_invoices.title')}
                    </h1>
                </header>

                {invoices.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        {t('tenant.my_invoices.empty')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {invoices.map((invoice) => (
                            <li
                                key={invoice.id}
                                className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex flex-col gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {invoice.service_name ?? '—'}
                                        </span>
                                        <Badge variant="outline">
                                            {t(
                                                `tenant.my_invoices.status.${invoice.status}`,
                                            )}
                                        </Badge>
                                    </div>
                                    <span className="text-sm text-muted-foreground">
                                        {t('tenant.my_invoices.number')}:{' '}
                                        <span className="font-mono">
                                            {invoice.number ?? '—'}
                                        </span>
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {t('tenant.my_invoices.code')}:{' '}
                                        <span className="font-mono">
                                            {invoice.booking_code ?? '—'}
                                        </span>
                                    </span>
                                    {invoice.issued_local ? (
                                        <span className="text-sm text-muted-foreground">
                                            {t('tenant.my_invoices.issued_at', {
                                                date: invoice.issued_local,
                                            })}
                                        </span>
                                    ) : null}
                                </div>

                                <div className="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                                    <span className="text-lg font-semibold text-primary">
                                        {formatMoney(
                                            invoice.amount_minor,
                                            invoice.currency,
                                        )}
                                    </span>
                                    {invoice.has_pdf ? (
                                        <Button asChild variant="outline" size="sm">
                                            <a
                                                href={`/my/invoices/${invoice.id}/pdf`}
                                            >
                                                <DownloadIcon className="size-4" />
                                                {t(
                                                    'tenant.my_invoices.download',
                                                )}
                                            </a>
                                        </Button>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
