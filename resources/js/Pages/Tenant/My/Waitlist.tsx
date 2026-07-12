import { Head } from '@inertiajs/react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/lib/i18n';
import type { MyWaitlistEntry } from '@/types';

type WaitlistProps = {
    entries: MyWaitlistEntry[];
};

/**
 * Members area — the customer's own waitlist positions (SLO-98). Read-only: the
 * queue position, status and (while an offer is live) the offer deadline.
 */
export default function Waitlist({ entries }: WaitlistProps) {
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('tenant.my_waitlist.title')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my_waitlist.title')}
                    </h1>
                </header>

                {entries.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        {t('tenant.my_waitlist.empty')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {entries.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex flex-col gap-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {entry.service_name ?? '—'}
                                        </span>
                                        <Badge variant="outline">
                                            {t(
                                                `tenant.waitlist_status.${entry.status}`,
                                            )}
                                        </Badge>
                                    </div>
                                    {entry.event_starts_local ? (
                                        <span className="text-sm text-muted-foreground">
                                            {t('tenant.my_waitlist.event')}:{' '}
                                            {entry.event_starts_local}
                                        </span>
                                    ) : null}
                                    {entry.offered_until_local ? (
                                        <span className="text-sm text-muted-foreground">
                                            {t(
                                                'tenant.my_waitlist.offer_deadline',
                                            )}{' '}
                                            {entry.offered_until_local}
                                        </span>
                                    ) : null}
                                    {entry.party_size > 1 ? (
                                        <span className="text-sm text-muted-foreground">
                                            {t('tenant.my_waitlist.party_size', {
                                                count: entry.party_size,
                                            })}
                                        </span>
                                    ) : null}
                                </div>

                                <span className="shrink-0 text-sm font-semibold text-primary">
                                    {t('tenant.my_waitlist.position', {
                                        position: entry.position,
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
