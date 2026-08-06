import { Head, useForm } from '@inertiajs/react';
import { LockIcon, RotateCcwIcon } from 'lucide-react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

type CatalogPermission = {
    code: string;
    /** Feature flag that makes this code untoggleable; null = freely editable. */
    locked_by: string | null;
};

type CatalogGroup = {
    key: string;
    permissions: CatalogPermission[];
};

type Role = {
    name: string;
    permissions: string[];
    editable: boolean;
    is_own: boolean;
    customized: boolean;
};

type IndexProps = {
    roles: Role[];
    groups: CatalogGroup[];
};

/**
 * The tenant's role editor (SLO-141): one card per seeded role, each listing the
 * permission catalog as checkboxes. Locked rows still render — greyed out with
 * the reason — because a silently absent permission reads as a bug, while a
 * locked one reads as an answer.
 */
export default function RolesIndex({ roles, groups }: IndexProps) {
    const t = useTranslations();

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.roles.title') }]}>
            <Head title={t('admin.roles.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.roles.title')}
                    description={t('admin.roles.subtitle')}
                />

                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t('admin.roles.admin_only_note')}
                </p>

                <div className="flex flex-col gap-4">
                    {roles.map((role) => (
                        <RoleCard key={role.name} role={role} groups={groups} />
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}

function RoleCard({ role, groups }: { role: Role; groups: CatalogGroup[] }) {
    const t = useTranslations();

    const form = useForm<{ permissions: string[] }>({ permissions: role.permissions });

    function toggle(code: string) {
        form.setData(
            'permissions',
            form.data.permissions.includes(code)
                ? form.data.permissions.filter((value) => value !== code)
                : [...form.data.permissions, code],
        );
    }

    function submit() {
        form.put(`/settings/roles/${role.name}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.roles.updated')),
        });
    }

    function reset() {
        if (!window.confirm(t('admin.roles.reset_confirm', { role: t(`role_name.${role.name}`) }))) {
            return;
        }

        // Posted through the same form object so `processing` disables both
        // buttons for the duration; the page reloads with the fresh grant, so
        // there is no local state to re-seed afterwards.
        form.post(`/settings/roles/${role.name}/reset`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.roles.reset_done')),
        });
    }

    const lockReason = role.is_own
        ? t('admin.roles.locked_own')
        : role.name === 'tenant-admin'
          ? t('admin.roles.locked_tenant_admin')
          : role.name === 'customer'
            ? t('admin.roles.locked_customer')
            : null;

    return (
        <section className="rounded-2xl border border-border bg-card p-5">
            <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-3">
                        <h2 className="font-medium">{t(`role_name.${role.name}`)}</h2>
                        <Badge variant={role.customized ? 'default' : 'secondary'}>
                            {role.customized
                                ? t('admin.roles.customized_badge')
                                : t('admin.roles.default_badge')}
                        </Badge>
                        {!role.editable ? (
                            <LockIcon
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        ) : null}
                    </div>
                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                        {t(`role_description.${role.name}`)}
                    </p>
                </div>

                {role.editable ? (
                    <div className="flex items-center gap-2">
                        {role.customized ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={reset}
                                disabled={form.processing}
                            >
                                <RotateCcwIcon className="size-4" aria-hidden="true" />
                                {t('admin.roles.reset')}
                            </Button>
                        ) : null}
                        <Button type="button" onClick={submit} disabled={form.processing}>
                            {t('admin.roles.save')}
                        </Button>
                    </div>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        {t('admin.roles.permission_count', {
                            count: role.permissions.length,
                        })}
                    </span>
                )}
            </header>

            {lockReason !== null ? (
                <p className="mb-4 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                    {lockReason}
                </p>
            ) : null}

            <div className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
                {groups.map((group) => (
                    <fieldset key={group.key} disabled={!role.editable}>
                        <legend className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {t(`admin.roles.group.${group.key}`)}
                        </legend>
                        <div className="flex flex-col">
                            {group.permissions.map((permission) => {
                                // A feature-locked code cannot be toggled either
                                // way: the grant it currently has is preserved by
                                // the server on every save, so the checkbox shows
                                // the truth rather than an editable lie.
                                const locked =
                                    !role.editable || permission.locked_by !== null;

                                return (
                                    <label
                                        key={permission.code}
                                        className={`flex items-start gap-2.5 py-1.5 text-sm ${
                                            locked ? 'text-muted-foreground' : ''
                                        }`}
                                        title={
                                            permission.locked_by !== null
                                                ? t('admin.roles.locked_feature', {
                                                      feature: t(
                                                          `features.${permission.locked_by}`,
                                                      ),
                                                  })
                                                : undefined
                                        }
                                    >
                                        <input
                                            type="checkbox"
                                            checked={form.data.permissions.includes(
                                                permission.code,
                                            )}
                                            disabled={locked}
                                            onChange={() => toggle(permission.code)}
                                            className="mt-0.5 size-4 rounded border-input"
                                        />
                                        <span>
                                            {t(`permission.${permission.code}`)}
                                            {permission.locked_by !== null ? (
                                                <span className="block text-xs">
                                                    {t('admin.roles.locked_feature', {
                                                        feature: t(
                                                            `features.${permission.locked_by}`,
                                                        ),
                                                    })}
                                                </span>
                                            ) : null}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                ))}
            </div>
        </section>
    );
}
