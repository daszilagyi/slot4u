import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, statusBadgeClass } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { Paginator, TenantSummary } from '@/types';

type IndexProps = {
    tenants: Paginator<TenantSummary>;
    filters: { search: string | null; status: string | null };
    statuses: string[];
};

export default function TenantsIndex({ tenants, filters, statuses }: IndexProps) {
    const t = useTranslations();
    const [search, setSearch] = useState(filters.search ?? '');

    function navigate(params: { search?: string; status?: string }) {
        router.get(
            '/tenants',
            {
                search: params.search ?? search,
                status: params.status ?? filters.status ?? '',
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        navigate({});
    }

    function act(tenant: TenantSummary, action: 'suspend' | 'activate') {
        router.post(`/tenants/${tenant.id}/${action}`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title={t('super.tenants.title')} />

            <div className="w-full max-w-6xl">
                <div className="mb-6 flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('super.tenants.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('super.tenants.subtitle')}
                    </p>
                </div>

                <form onSubmit={submitSearch} className="mb-4 flex gap-3">
                    <Input
                        value={search}
                        placeholder={t('super.tenants.search')}
                        onChange={(e) => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                    <select
                        value={filters.status ?? ''}
                        onChange={(e) => navigate({ status: e.target.value })}
                        aria-label={t('super.tenants.col.status')}
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="">{t('super.tenants.filter_all')}</option>
                        {statuses.map((s) => (
                            <option key={s} value={s}>
                                {t(`tenant_status.${s}`)}
                            </option>
                        ))}
                    </select>
                    <Button type="submit" variant="outline">
                        {t('super.tenants.search_button')}
                    </Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.name')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.status')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.users')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.trial_ends')}</th>
                                <th className="px-4 py-3 text-right font-medium">{t('super.tenants.col.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                        {t('super.tenants.empty')}
                                    </td>
                                </tr>
                            ) : (
                                tenants.data.map((tenant) => (
                                    <tr key={tenant.id} className="border-t border-border">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{tenant.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {tenant.slug}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusBadgeClass(tenant.status)}`}>
                                                {t(`tenant_status.${tenant.status}`)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {tenant.users_count}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {tenant.trial_ends_at
                                                ? formatDate(tenant.trial_ends_at)
                                                : t('super.tenants.show.no_trial')}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                {tenant.status === 'suspended' || tenant.archived ? (
                                                    <Button size="sm" variant="outline" onClick={() => act(tenant, 'activate')}>
                                                        {t('super.tenants.action.activate')}
                                                    </Button>
                                                ) : (
                                                    <Button size="sm" variant="outline" onClick={() => act(tenant, 'suspend')}>
                                                        {t('super.tenants.action.suspend')}
                                                    </Button>
                                                )}
                                                <Button size="sm" asChild>
                                                    <Link href={`/tenants/${tenant.id}`}>
                                                        {t('super.tenants.action.view')}
                                                    </Link>
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {tenants.last_page > 1 ? (
                    <div className="mt-4 flex flex-wrap gap-1">
                        {tenants.links.map((link, i) => (
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
