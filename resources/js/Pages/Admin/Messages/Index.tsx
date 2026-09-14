import { Head, Link, router } from '@inertiajs/react';
import { MessagesSquareIcon } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/components/admin/EmptyState';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { MessageThreadSummary, Paginator } from '@/types';

type IndexProps = {
    threads: Paginator<MessageThreadSummary>;
};

/** The tenant's message inbox: one row per customer thread (SLO-36). */
export default function MessagesIndex({ threads }: IndexProps) {
    const t = useTranslations();

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.messages.title') }]}>
            <Head title={t('admin.messages.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.messages.title')}
                    description={t('admin.messages.subtitle')}
                />

                {threads.data.length === 0 ? (
                    <EmptyState
                        icon={MessagesSquareIcon}
                        title={t('admin.messages.empty')}
                    />
                ) : (
                    <ul className="flex flex-col overflow-hidden rounded-xl border border-border">
                        {threads.data.map((thread) => (
                            <li
                                key={thread.customer_id}
                                className="border-t border-border first:border-t-0"
                            >
                                <Link
                                    href={`/messages/${thread.customer_id}`}
                                    className="flex items-start justify-between gap-4 px-4 py-3 transition-colors hover:bg-accent"
                                >
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <span
                                            className={cn(
                                                'truncate text-sm',
                                                thread.unread > 0
                                                    ? 'font-semibold'
                                                    : 'font-medium',
                                            )}
                                        >
                                            {thread.customer_name ?? '—'}
                                        </span>
                                        <span className="truncate text-sm text-muted-foreground">
                                            {thread.last_from_customer
                                                ? ''
                                                : `${t('messages.you')}: `}
                                            {thread.preview}
                                        </span>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        <span className="text-xs text-muted-foreground">
                                            {thread.last_local}
                                        </span>
                                        {thread.unread > 0 ? (
                                            <Badge>
                                                {t('admin.messages.unread', {
                                                    count: thread.unread,
                                                })}
                                            </Badge>
                                        ) : null}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {threads.last_page > 1 ? (
                    <div className="flex flex-wrap gap-1">
                        {threads.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className={`rounded-md px-3 py-1.5 text-sm ${
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-accent disabled:opacity-40'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AdminLayout>
    );
}
