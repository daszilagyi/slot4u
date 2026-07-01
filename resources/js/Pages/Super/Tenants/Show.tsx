import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, statusBadgeClass } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { TenantSummary } from '@/types';

type TenantDetail = TenantSummary & { timezone: string; locale: string };
type FeatureState = { code: string; enabled: boolean };

type ShowProps = {
    tenant: TenantDetail;
    featureStates: FeatureState[];
};

export default function TenantsShow({ tenant, featureStates }: ShowProps) {
    const t = useTranslations();

    const form = useForm({
        name: tenant.name,
        slug: tenant.slug,
        timezone: tenant.timezone,
        locale: tenant.locale,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(`/tenants/${tenant.id}`, { preserveScroll: true });
    }

    function post(action: string) {
        router.post(
            `/tenants/${tenant.id}/${action}`,
            {},
            { preserveScroll: true },
        );
    }

    // Impersonation lands the superadmin on the tenant subdomain, so it is a
    // full cross-domain visit (server responds with Inertia::location).
    function impersonate() {
        router.post(`/tenants/${tenant.id}/impersonate`);
    }

    function archive() {
        if (window.confirm(t('super.tenants.confirm.archive'))) {
            post('archive');
        }
    }

    function toggleFeature(feature: FeatureState) {
        router.post(
            `/tenants/${tenant.id}/features`,
            { feature: feature.code, enabled: !feature.enabled },
            { preserveScroll: true },
        );
    }

    return (
        <AppLayout>
            <Head title={tenant.name} />

            <div className="flex w-full max-w-3xl flex-col gap-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {tenant.name}
                        </h1>
                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusBadgeClass(tenant.status)}`}
                        >
                            {t(`tenant_status.${tenant.status}`)}
                        </span>
                    </div>
                    <Link
                        href="/tenants"
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        {t('super.tenants.action.back')}
                    </Link>
                </div>

                {/* Base fields */}
                <section className="rounded-xl border border-border p-6">
                    <h2 className="mb-4 text-lg font-medium">
                        {t('super.tenants.show.edit_title')}
                    </h2>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="name">
                                {t('super.tenants.field.name')}
                            </Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <p className="text-sm text-red-500">
                                    {form.errors.name}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="slug">
                                {t('super.tenants.field.slug')}
                            </Label>
                            <Input
                                id="slug"
                                value={form.data.slug}
                                onChange={(e) =>
                                    form.setData('slug', e.target.value)
                                }
                            />
                            {form.errors.slug ? (
                                <p className="text-sm text-red-500">
                                    {form.errors.slug}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="timezone">
                                {t('super.tenants.field.timezone')}
                            </Label>
                            <Input
                                id="timezone"
                                value={form.data.timezone}
                                onChange={(e) =>
                                    form.setData('timezone', e.target.value)
                                }
                            />
                            {form.errors.timezone ? (
                                <p className="text-sm text-red-500">
                                    {form.errors.timezone}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="locale">
                                {t('super.tenants.field.locale')}
                            </Label>
                            <select
                                id="locale"
                                value={form.data.locale}
                                onChange={(e) =>
                                    form.setData('locale', e.target.value)
                                }
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                <option value="hu">{t('locale.hu')}</option>
                                <option value="en">{t('locale.en')}</option>
                            </select>
                        </div>
                        <Button
                            type="submit"
                            className="self-start"
                            disabled={form.processing}
                        >
                            {t('super.tenants.action.save')}
                        </Button>
                    </form>
                </section>

                {/* Status actions */}
                <section className="rounded-xl border border-border p-6">
                    <h2 className="mb-1 text-lg font-medium">
                        {t('super.tenants.show.actions_title')}
                    </h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        {t('super.tenants.col.trial_ends')}:{' '}
                        {tenant.trial_ends_at
                            ? formatDate(tenant.trial_ends_at)
                            : t('super.tenants.show.no_trial')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {tenant.status === 'suspended' || tenant.archived ? (
                            <Button
                                variant="outline"
                                onClick={() => post('activate')}
                            >
                                {t('super.tenants.action.activate')}
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                onClick={() => post('suspend')}
                            >
                                {t('super.tenants.action.suspend')}
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            onClick={() => post('extend-trial')}
                        >
                            {t('super.tenants.action.extend_trial')}
                        </Button>
                        {tenant.archived ? null : (
                            <Button variant="outline" onClick={archive}>
                                {t('super.tenants.action.archive')}
                            </Button>
                        )}
                        {!tenant.archived && tenant.status !== 'suspended' ? (
                            <Button onClick={impersonate}>
                                {t('super.tenants.action.impersonate')}
                            </Button>
                        ) : null}
                    </div>
                </section>

                {/* Feature toggles */}
                <section className="rounded-xl border border-border p-6">
                    <h2 className="mb-4 text-lg font-medium">
                        {t('super.tenants.show.features_title')}
                    </h2>
                    <div className="flex flex-col divide-y divide-border">
                        {featureStates.map((feature) => (
                            <label
                                key={feature.code}
                                className="flex items-center justify-between py-2.5 text-sm"
                            >
                                <span>{t(`features.${feature.code}`)}</span>
                                <input
                                    type="checkbox"
                                    checked={feature.enabled}
                                    onChange={() => toggleFeature(feature)}
                                    className="size-4 rounded border-input"
                                />
                            </label>
                        ))}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
