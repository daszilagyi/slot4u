import { Head, router } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { AuditLogEntry, Paginator } from '@/types';

type IndexProps = {
    logs: Paginator<AuditLogEntry>;
    filters: { action: string | null; tenant_id: number | null };
    actions: string[];
};

export default function AuditLogsIndex({ logs, filters, actions }: IndexProps) {
    const t = useTranslations();

    function filterByAction(action: string) {
        router.get(
            '/audit-logs',
            { action, tenant_id: filters.tenant_id ?? '' },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function renderValues(values: Record<string, unknown> | null): string {
        if (!values || Object.keys(values).length === 0) {
            return '—';
        }

        return Object.entries(values)
            .map(([key, value]) => `${key}: ${value === null ? '∅' : String(value)}`)
            .join(', ');
    }

    return (
        <AppLayout>
            <Head title={t('super.audit.title')} />

            <div className="w-full max-w-6xl">
                <div className="mb-6 flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('super.audit.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('super.audit.subtitle')}
                    </p>
                </div>

                <div className="mb-4 flex gap-3">
                    <select
                        value={filters.action ?? ''}
                        onChange={(e) => filterByAction(e.target.value)}
                        aria-label={t('super.audit.col.action')}
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="">{t('super.audit.filter_all')}</option>
                        {actions.map((action) => (
                            <option key={action} value={action}>
                                {t(`audit_action.${action}`)}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="overflow-hidden rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">{t('super.audit.col.time')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.audit.col.action')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.audit.col.actor')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.audit.col.tenant')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.audit.col.changes')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.audit.col.ip')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                        {t('super.audit.empty')}
                                    </td>
                                </tr>
                            ) : (
                                logs.data.map((log) => (
                                    <tr key={log.id} className="border-t border-border align-top">
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {formatDateTime(log.created_at)}
                                        </td>
                                        <td className="px-4 py-3 font-medium">
                                            {t(`audit_action.${log.action}`)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {log.actor ? (
                                                <div>
                                                    <div>{log.actor.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {log.actor.email}
                                                    </div>
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {t('super.audit.system_actor')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {log.tenant ? log.tenant.name : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">
                                            <div>
                                                <span className="font-medium">{t('super.audit.old_label')}:</span>{' '}
                                                {renderValues(log.old_values)}
                                            </div>
                                            <div>
                                                <span className="font-medium">{t('super.audit.new_label')}:</span>{' '}
                                                {renderValues(log.new_values)}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {log.ip_address ?? '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {logs.last_page > 1 ? (
                    <div className="mt-4 flex flex-wrap gap-1">
                        {logs.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
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
        </AppLayout>
    );
}
