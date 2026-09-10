import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DEMO_BADGE_CLASS, formatDate, statusBadgeClass } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { Paginator, SignupSourceCount, TenantSummary } from '@/types';

type IndexProps = {
    tenants: Paginator<TenantSummary>;
    filters: { search: string | null; status: string | null; source: string | null };
    statuses: string[];
    sources: SignupSourceCount[];
};

/**
 * The campaign that brought a tenant, in one line for a table cell (SLO-210).
 *
 * Falls back to the landing page when there is no `utm_source`: someone who
 * found `/autoszerviz` through search is still attributable to that vertical,
 * which is half of what docs/22 §7.1 wants to compare.
 */
function sourceLabel(signup: TenantSummary['signup']): string | null {
    if (signup === null) {
        return null;
    }

    return signup.utm_source ?? signup.landing_path;
}

export default function TenantsIndex({ tenants, filters, statuses, sources }: IndexProps) {
    const t = useTranslations();
    const [search, setSearch] = useState(filters.search ?? '');

    function navigate(params: { search?: string; status?: string; source?: string }) {
        router.get(
            '/tenants',
            {
                search: params.search ?? search,
                status: params.status ?? filters.status ?? '',
                source: params.source ?? filters.source ?? '',
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

                {/*
                    The acquisition breakdown (SLO-210). Chips rather than a
                    select, because the counts ARE the answer: "which campaign
                    produced tenants" is read at a glance here, and clicking one
                    only narrows the list to look closer.

                    ⚠️ The unknown bucket is a number, not a filter. It is the
                    denominator everything else is judged against — most of it is
                    tenants that predate the measurement — and offering it as a
                    working set would suggest it is a cohort rather than a gap.
                */}
                {sources.length > 0 && (
                    <div
                        className="mb-4 flex flex-wrap items-center gap-2"
                        aria-label={t('super.tenants.source.heading')}
                    >
                        <span className="text-xs text-muted-foreground">
                            {t('super.tenants.source.heading')}
                        </span>
                        <button
                            type="button"
                            onClick={() => navigate({ source: '' })}
                            className={`rounded-full border px-2.5 py-0.5 text-xs ${
                                filters.source === null
                                    ? 'border-primary bg-primary/10 font-medium text-foreground'
                                    : 'border-border text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {t('super.tenants.source.all')}
                        </button>
                        {sources.map((row) =>
                            row.source === null ? (
                                <span
                                    key="unknown"
                                    title={t('super.tenants.source.unknown_hint')}
                                    className="rounded-full border border-dashed border-border px-2.5 py-0.5 text-xs text-muted-foreground"
                                >
                                    {t('super.tenants.source.unknown')} · {row.total}
                                </span>
                            ) : (
                                <button
                                    key={row.source}
                                    type="button"
                                    onClick={() => navigate({ source: row.source ?? '' })}
                                    className={`rounded-full border px-2.5 py-0.5 text-xs ${
                                        filters.source === row.source
                                            ? 'border-primary bg-primary/10 font-medium text-foreground'
                                            : 'border-border text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {row.source} · {row.total}
                                </button>
                            ),
                        )}
                    </div>
                )}

                <div className="overflow-hidden rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.name')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.status')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.users')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.source')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.tenants.col.trial_ends')}</th>
                                <th className="px-4 py-3 text-right font-medium">{t('super.tenants.col.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
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
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusBadgeClass(tenant.status)}`}>
                                                    {t(`tenant_status.${tenant.status}`)}
                                                </span>
                                                {tenant.is_demo && (
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${DEMO_BADGE_CLASS}`}
                                                        title={t('super.tenants.demo.hint')}
                                                    >
                                                        {t('super.tenants.demo.badge')}
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {tenant.users_count}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {sourceLabel(tenant.signup) ?? (
                                                <span title={t('super.tenants.source.unknown_hint')}>
                                                    {t('super.tenants.source.unknown')}
                                                </span>
                                            )}
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
